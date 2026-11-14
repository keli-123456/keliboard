"""Read-only classification of native candidates, installed only in fresh frozen adapters."""
import hashlib
import json
import re
from types import SimpleNamespace
import replacement_diagnostics


FIELDS = ('id', 'label', 'marker', 'status', 'external_id', 'provider_created_at', 'dispatched_at')
CHECKS = ('task_terminal', 'dispatch_unset', 'provider_identity_unset', 'bootstrap_unset', 'step_history_complete',
          'queued_recorded', 'terminal_recorded', 'no_dispatch_steps', 'native_binding')
REPORT = 'cleanup-task-classification.json'


def proof_projection(proof):
    if not (isinstance(proof, dict) and set(proof) == {'schema', 'task_sha256', 'checks'}
            and type(proof['schema']) is int and proof['schema'] == 1
            and isinstance(proof['task_sha256'], str) and re.fullmatch('[a-f0-9]{64}', proof['task_sha256'])
            and isinstance(proof['checks'], dict) and set(proof['checks']) == set(CHECKS)
            and all(type(v) is bool for v in proof['checks'].values())):
        return None
    return {'schema': 1, 'task_sha256': proof['task_sha256'], 'checks': {name: proof['checks'][name] for name in CHECKS}}


def classify(run_id, task, inventory):
    result = {'classification': 'needs_review', 'reason': 'dispatch_evidence_unverified', 'skip_cloud_reconcile': False}
    if not (isinstance(run_id, str) and re.fullmatch(r'keli-accept-[a-z0-9-]{8,100}', run_id)
            and isinstance(task, dict) and all(key in task for key in FIELDS)
            and isinstance(task['id'], str) and re.fullmatch(r'[a-f0-9-]{36}', task['id'])
            and task['label'] == run_id + '-candidate' and isinstance(task['marker'], str)
            and re.fullmatch(r'keli-create-[a-f0-9]{24}', task['marker'])):
        return result
    if task['dispatched_at'] is not None:
        return dict(result, classification='dispatched', reason='original_receipt_or_reconciliation_required')
    if task['external_id'] is not None or task['provider_created_at'] is not None:
        return dict(result, reason='dispatch_receipt_inconsistent')
    proof = proof_projection(task.get('dispatch_evidence'))
    if proof is None:
        return result
    digest = hashlib.sha256(json.dumps([task[key] for key in FIELDS], separators=(',', ':'), ensure_ascii=True).encode()).hexdigest()
    if proof['task_sha256'] != digest or task['status'] not in ('failed', 'cancelled') or not all(proof['checks'].values()):
        return result
    if (not isinstance(inventory, list) or any(not isinstance(row, dict) or type(row.get('id')) is not int
            or not isinstance(row.get('label'), str) or not isinstance(row.get('tags'), list)
            or any(not isinstance(tag, str) for tag in row['tags']) for row in inventory)
            or len({row['id'] for row in inventory}) != len(inventory)):
        return dict(result, reason='complete_inventory_required')
    if any(row['label'] == task['label'] or task['marker'] in row['tags'] for row in inventory):
        return dict(result, reason='undispatched_inventory_conflict')
    return {'classification': 'not_dispatched', 'reason': 'terminal_boundary_and_inventory_verified', 'skip_cloud_reconcile': True}


def validate_bindings(base):
    import ast
    cleanup = ast.parse((base / 'cleanup.py').read_text(encoding='utf-8'))
    classes = [node for node in cleanup.body if isinstance(node, ast.ClassDef) and node.name == 'Cleanup']
    if (len(classes) != 1 or not {'tasks', 'receipts_cloud', 'collect'} <= {node.name for node in classes[0].body if isinstance(node, ast.FunctionDef)}
            or not any(isinstance(node, ast.FunctionDef) and node.name == 'controller_evidence' for node in cleanup.body)):
        raise ValueError('task_evidence_binding_required')
    cli = (base / 'live_cli.php').read_text(encoding='utf-8')
    tree = ast.parse((base / 'teardown_control.py').read_text(encoding='utf-8'))
    values = [node.value for node in tree.body if isinstance(node, ast.Assign)
              and any(isinstance(key, ast.Name) and key.id == 'COLLECT_PHP' for key in node.targets)]
    if len(values) != 1 or not isinstance(values[0], ast.BinOp) or not isinstance(values[0].op, ast.Add):
        raise ValueError('task_evidence_binding_required')
    php = ast.literal_eval(values[0].right)
    if not (isinstance(php, str) and '\\App\\Services\\ServerMachine\\Cloud\\MachineProvisionTaskEvidence::snapshot($t)' in php
            and '\\App\\Services\\ServerMachine\\Cloud\\MachineProvisionTaskEvidence::snapshot($task)' in cli):
        raise ValueError('task_evidence_binding_required')


def install(module):
    original = module.Cleanup
    if getattr(original, '_task_classification_installed', False):
        return module
    original_projection = module.controller_evidence

    def controller_evidence(value):
        result = original_projection(value)
        for raw, safe in zip(value['tasks'], result['tasks'], strict=True):
            proof = proof_projection(raw.get('dispatch_evidence'))
            if proof is not None:
                safe['dispatch_evidence'] = proof
            try:
                if raw.get('replacement_diagnostic') is not None:
                    safe['replacement_diagnostic'] = replacement_diagnostics.project(raw['replacement_diagnostic'])
            except ValueError:
                pass
        return result

    class ClassifiedCleanup(original):
        _task_classification_installed = True

        def collect(self):
            result = super().collect()
            context = SimpleNamespace(RUN=module.RUN, BASE=self.store.path(REPORT).parent,
                                      save=self.store.save, load=self.store.load)
            captured = replacement_diagnostics.capture_result(context, {'command': 'native-status', 'ok': True,
                'tasks': self.controller.get('tasks', [])})
            if 'replacement_diagnostic_preservation' in captured:
                preservation = captured['replacement_diagnostic_preservation']
                try:
                    self.store.save('replacement-error-export-readback.json', preservation)
                except Exception:
                    preservation = {'ok': False, 'reason': 'replacement_diagnostic_unconfirmed'}
                result = dict(result, replacement_diagnostic_preservation=preservation)
            return result

        def receipts_cloud(self, inventory=None):
            self._classification_inventory = inventory
            self._classifying_tasks = True
            try:
                return super().receipts_cloud(inventory)
            finally:
                self._classifying_tasks = False

        def tasks(self):
            tasks = super().tasks()
            if not getattr(self, '_classifying_tasks', False):
                return tasks
            selected, records = [], []
            for task in tasks:
                if task.get('label') != module.RUN + '-candidate':
                    selected.append(task)
                    continue
                # Use the authoritative controller export, not a local ledger with omitted NULLs.
                matches = [row for row in self.controller.get('tasks', []) if isinstance(row, dict)
                           and all(key in row and row[key] == task.get(key) for key in FIELDS)]
                decisions = [classify(module.RUN, row, self._classification_inventory) for row in matches]
                decision = decisions[-1] if decisions else classify(module.RUN, task, self._classification_inventory)
                if any(item != decision for item in decisions):
                    decision = {'classification': 'needs_review', 'reason': 'controller_task_proof_conflict', 'skip_cloud_reconcile': False}
                # Any retained receipt or dispatch boundary must still go through the original identity guard.
                conflict = any(row.get('role') == 'candidate' for row in self.cloud)
                if conflict:
                    decision = {'classification': 'needs_review', 'reason': 'retained_receipt_requires_reconciliation', 'skip_cloud_reconcile': False}
                records.append({'task_id': task['id'], 'role': 'candidate', **decision})
                if not decision['skip_cloud_reconcile']:
                    selected.append(task)
            value = {'schema': 1, 'run_id': module.RUN, 'records': records, 'provider_mutations': 0}
            module.require(not self.store.path('closed.json').exists(), 'new_round_required')
            self.store.save(REPORT, value)
            module.require(self.store.load(REPORT) == value, 'task_classification_readback_failed')
            return selected

    module.Cleanup = ClassifiedCleanup
    module.controller_evidence = controller_evidence
    return module
