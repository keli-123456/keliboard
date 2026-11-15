"""One existing-candidate readback per call; no create, native advancement or DNS operation."""
import uuid


class Refused(RuntimeError):
    pass


def ready_for_sampling(task):
    return (isinstance(task, dict) and task.get('bootstrap_state') == 'succeeded'
        and isinstance(task.get('status'), str)
        and task.get('status') in {'awaiting_acceptance', 'waiting_ipv4_probe'})


def process(driver, task_id):
    stop = getattr(driver, 'sampling_stop_type', Refused)
    task = driver.tasks.get('candidate', {})
    try:
        valid_id = isinstance(task_id, str) and str(uuid.UUID(task_id)) == task_id
    except ValueError:
        valid_id = False
    if (not valid_id or not isinstance(task, dict) or task.get('id') != task_id
            or task.get('label') != driver.campaign.RUN + '-candidate'
            or not isinstance(task.get('external_id'), str) or not 1 <= len(task['external_id']) <= 64
            or type(task.get('dispatched_at')) is not int or task['dispatched_at'] <= 0
            or not isinstance(task.get('provider_created_at'), str) or not task['provider_created_at']):
        raise stop('candidate_readback_identity_required')
    value = driver.command('native-advance', task_id)
    if (not isinstance(value, dict) or value.get('candidate_process_only') is not True
            or value.get('candidate_task_id') != task_id or value.get('native_phase_advanced') is not False
            or value.get('dns_cutover_dispatched') is not False):
        raise stop('candidate_readback_proof_required')
    return value
