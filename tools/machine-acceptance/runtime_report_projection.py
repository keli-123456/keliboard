"""Pure collector boundary for a separately pinned runtime/control harness."""


def number(value, maximum=2**63 - 1):
    return value if type(value) is int and 0 <= value <= maximum else None


def control_diagnostics(out, c, d):
    if 'first_error' in out:
        out['first_error'] = c.safe_error(out['first_error'], d)
        out['error_code'] = out['first_error']['reason']
        if 'error_class' in out['first_error']:
            out['error_class'] = out['first_error']['error_class']
    errors = []
    for item in out.get('secondary_errors', []):
        error = c.safe_error(item, d)
        if error != out.get('first_error') and error not in errors:
            errors.append(error)
    out['secondary_errors'] = errors
    return out


def project_report(name, value, run, h, c, b=None):
    d = h.diagnostics()
    out = {'schema': 1, 'run_id': run, 'ok': False}
    if name in {'child-bootstrap-result.json', 'child-bootstrap-first-error.json'}:
        if b is None or not isinstance(value, dict) or value.get('run_id') != run:
            d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
            return out
        return b.project(value, checkpoint=name.endswith('first-error.json'))
    if name not in {'runtime-result.json', 'control-result.json', 'runtime-first-error.json',
                    'control-first-error.json', 'first-error.json', 'iputils.first-error.json'}:
        d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
        return out
    if not isinstance(value, dict):
        d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
        value = {}
    control = name.startswith('control-')
    if control:
        if value.get('first_error') is not None:
            if isinstance(value['first_error'], dict):
                out['first_error'] = c.safe_error(value['first_error'], d)
            else:
                d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
        elif any(key in value for key in ('error_code', 'reason', 'error_class')):
            out['first_error'] = c.safe_error(value, d)
        errors = value.get('secondary_errors', [])
        out['secondary_errors'] = []
        if isinstance(errors, list):
            for item in errors[:8]:
                if isinstance(item, dict):
                    error = c.safe_error(item, d)
                    if error != out.get('first_error') and error not in out['secondary_errors']:
                        out['secondary_errors'].append(error)
            if any(not isinstance(item, dict) for item in errors[:8]):
                d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
            if len(errors) > 8:
                out['secondary_errors_truncated'] = True
        else:
            d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
        if 'secondary_errors_truncated' in value:
            truncated = value['secondary_errors_truncated']
            if type(truncated) is not bool or truncated and 'first_error' not in out:
                d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
            if truncated is True:
                out['secondary_errors_truncated'] = True
        if 'secondary_errors_dropped' in value:
            dropped = number(value['secondary_errors_dropped'], 65535)
            out['secondary_errors_dropped'] = dropped
            if dropped is None or dropped and 'first_error' not in out:
                d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
    else:
        d.inherit_projected_errors(out, value, 'runtime')
    if type(value.get('schema')) is not int or value['schema'] != 1 or (
            'run_id' in value and value['run_id'] != run):
        d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
    if name.endswith('first-error.json'):
        if not isinstance(value.get('first_error'), dict) or name != 'iputils.first-error.json' and value.get('run_id') != run:
            d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
        out['checkpoint'] = True
        return control_diagnostics(out, c, d) if control else out
    if value.get('run_id') != run:
        d.remember_projected_error(out, {'reason': 'report_missing_or_invalid'}, 'runtime_read')
    try:
        resources = value.get('resources', {})
        out['resources'] = {key: number(resources.get(key)) for key in (
            'cpu_usage_usec', 'oom_kills', 'rss_samples', 'rss_sum_sampled_max_bytes')} if isinstance(resources, dict) else {}
        limits = value.get('cgroup_limits', {})
        out['cgroup_limits'] = {key: number(limits.get(key)) for key in (
            'cpu_quota_us', 'cpu_period_us', 'memory_max_bytes')} if isinstance(limits, dict) else {}
        resources_ok = (len(out['resources']) == 4 and all(v is not None for v in out['resources'].values())
            and out['resources']['oom_kills'] == 0 and out['resources']['rss_samples'] > 0
            and len(out['cgroup_limits']) == 3 and all(v is not None for v in out['cgroup_limits'].values())
            and 0 < out['cgroup_limits']['cpu_quota_us'] * 4 <= out['cgroup_limits']['cpu_period_us']
            and 0 < out['cgroup_limits']['memory_max_bytes'] <= 448 * 1024 * 1024)
        if control:
            out.update(expected=len(c.FLAGS), completed=number(value.get('completed'), 5),
                skipped=number(value.get('skipped'), 5), protocol_retries=number(value.get('protocol_retries'), 5), controls=[])
            for key in ('namespace_reaped', 'fixture_files_removed', 'temporary_files_removed', 'first_error_persisted'):
                out[key] = value.get(key) is True
            phase = value.get('phase')
            if isinstance(phase, str) and phase in c.PHASES:
                out['phase'] = phase
            case = value.get('current_case')
            if isinstance(case, str) and case in c.FLAGS:
                out['current_case'] = case
            rows = value.get('controls')
            if not isinstance(rows, list) or len(rows) > len(c.FLAGS):
                raise ValueError()
            for case, row in zip(c.FLAGS, rows):
                out['controls'].append(c.checked(row, case, d))
            out['ok'] = (value.get('ok') is True and type(value.get('expected')) is int and value['expected'] == len(c.FLAGS)
                and out['completed'] == len(c.FLAGS) and len(out['controls']) == len(c.FLAGS)
                and out['skipped'] == out['protocol_retries'] == 0 and out.get('phase') == 'complete'
                and all(out[key] for key in ('namespace_reaped', 'fixture_files_removed', 'temporary_files_removed'))
                and 'first_error' not in out and not out.get('secondary_errors') and resources_ok)
        else:
            for key, expected in (('node_sha256', h.NODE_SHA256), ('sing_box_sha256', h.SING_SHA256)):
                if value.get(key) != expected:
                    raise ValueError()
                out[key] = expected
            out['runtime'] = d.project_runtime(value.get('runtime'))
            out['iputils'] = h.safe_iputils(value.get('iputils'))
            d.inherit_projected_errors(out, out['runtime'], 'runtime')
            d.inherit_projected_errors(out, out['iputils'], 'iputils')
            for key in ('temporary_files_removed', 'staged_inputs_verified', 'first_error_persisted'):
                out[key] = value.get(key) is True
            out['supervisor_stderr_error_lines'] = number(value.get('supervisor_stderr_error_lines'), 100000)
            out['network_scope'] = 'fresh_private_loopback_only' if value.get('network_scope') == 'fresh_private_loopback_only' else 'unverified'
            out['limitations'] = list(h.LIMITATIONS)
            out['ok'] = (value.get('ok') is True and out['runtime']['ok'] is True and out['iputils']['ok'] is True
                and out['temporary_files_removed'] and out['staged_inputs_verified'] and resources_ok
                and out['supervisor_stderr_error_lines'] is not None
                and out['network_scope'] == 'fresh_private_loopback_only' and 'first_error' not in out)
        if not out['ok'] and 'first_error' not in out:
            d.remember_projected_error(out, {'reason': 'incomplete_runtime_evidence'}, 'runtime_projection')
    except BaseException as error:
        if control:
            error = c.safe_error(error, d)
            if 'first_error' not in out:
                out['first_error'] = error
            elif error != out['first_error'] and error not in out.setdefault('secondary_errors', []):
                if len(out['secondary_errors']) < 8:
                    out['secondary_errors'].append(error)
                else:
                    out['secondary_errors_truncated'] = True
        else:
            d.remember_projected_error(out, error, 'runtime_projection')
        out['ok'] = False
    return control_diagnostics(out, c, d) if control else out
