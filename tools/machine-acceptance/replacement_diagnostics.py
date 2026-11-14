"""Fixed-field replacement rejection projection and immutable local first-error readback."""
import os
from pathlib import Path
import re
import stat


REPORT = 'replacement-first-error.json'
CHECKS = {
    'replacement_context': ('context_available', 'context_matches', 'installation_matches'),
    'replacement_decision': ('evidence_current', 'threshold_reached', 'probe_failed', 'decision_matches', 'episode_present'),
    'native_source': ('installation_matches', 'context_matches', 'evidence_current', 'threshold_reached', 'probe_failed', 'episode_matches'),
    'dispatch_reservation': ('revision_matches', 'episode_matches'),
}


def project(value):
    if not (isinstance(value, dict) and set(value) == {'schema', 'guard', 'checks', 'failed_fields'}
            and type(value['schema']) is int and value['schema'] == 1 and isinstance(value['guard'], str)
            and value['guard'] in CHECKS and isinstance(value['checks'], dict)):
        raise ValueError('replacement_diagnostic_invalid')
    names = CHECKS[value['guard']]
    if set(value['checks']) != set(names) or any(type(v) is not bool for v in value['checks'].values()):
        raise ValueError('replacement_diagnostic_invalid')
    failed = [name for name in names if not value['checks'][name]]
    if not failed or value['failed_fields'] != failed:
        raise ValueError('replacement_diagnostic_invalid')
    return {'schema': 1, 'guard': value['guard'], 'checks': {name: value['checks'][name] for name in names}, 'failed_fields': failed}


def capture_result(campaign, result):
    if not isinstance(result, dict) or result.get('command') not in ('native-start', 'native-advance', 'native-status', 'process'):
        return result
    safe = dict(result)
    try:
        candidates = []
        rejected = False
        groups = [('tasks', result.get('tasks', [])), ('members', result.get('run', {}).get('members', []))]
        pending = []
        for key, rows in groups:
            if not isinstance(rows, list) or len(rows) > 100:
                raise ValueError('replacement_diagnostic_invalid')
            projected = []
            if key == 'tasks':
                safe['tasks'] = projected
            else:
                safe['run'] = dict(result.get('run', {}), members=projected)
            for original in rows:
                row = dict(original) if isinstance(original, dict) else None
                if not isinstance(row, dict):
                    raise ValueError('replacement_diagnostic_invalid')
                diagnostic = row.pop('replacement_diagnostic', None)
                projected.append(row)
                rejected = rejected or row.get('reason') == 'replacement_evidence_changed'
                if diagnostic is not None:
                    pending.append((row, diagnostic))
        for row, raw in pending:
            row['replacement_diagnostic'] = project(raw)
            candidates.append(row['replacement_diagnostic'])
        if not candidates:
            if rejected:
                safe['replacement_diagnostic_preservation'] = {'ok': False, 'reason': 'replacement_diagnostic_missing'}
            return safe
        if not (isinstance(campaign.RUN, str) and re.fullmatch(r'keli-accept-[a-z0-9-]{8,100}', campaign.RUN)):
            raise ValueError('replacement_diagnostic_invalid')
        base = Path(campaign.BASE)
        if os.path.lexists(base / 'closed.json'):
            raise ValueError('closed_round')
        value = {'schema': 1, 'run_id': campaign.RUN, 'reason': 'replacement_evidence_changed', 'diagnostic': candidates[0]}
        path = base / REPORT

        def readback():
            info = path.lstat()
            if not (stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and 0 < info.st_size <= 8192):
                raise ValueError('replacement_diagnostic_invalid')
            if os.name == 'posix' and (info.st_uid != os.geteuid() or info.st_mode & 0o077):
                raise ValueError('replacement_diagnostic_invalid')
            stored = campaign.load(REPORT)
            if (not isinstance(stored, dict) or set(stored) != set(value) or type(stored.get('schema')) is not int or stored['schema'] != 1
                    or stored.get('run_id') != campaign.RUN or stored.get('reason') != value['reason']):
                raise ValueError('replacement_diagnostic_invalid')
            project(stored['diagnostic'])
            return stored

        if os.path.lexists(path):
            first = readback()
        else:
            try:
                campaign.save(REPORT, value, exclusive=True)
            except FileExistsError:
                pass
            first = readback()
        safe['replacement_diagnostic_preservation'] = {'ok': True, 'independent_local_readback_verified': True,
            'original_first_error_retained': first != value, 'diagnostic': first['diagnostic']}
    except Exception:
        safe['replacement_diagnostic_preservation'] = {'ok': False, 'reason': 'replacement_diagnostic_unconfirmed'}
    return safe
