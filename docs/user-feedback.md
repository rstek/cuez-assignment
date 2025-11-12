# User feedback (high level)
Keep users informed during workflows (e.g., duplication) without impl details.
Surfaces:
- In-app realtime via broadcasts; fallback to polling.
- Out-of-band (email/Slack/sms/push) for long jobs.

Duplication journey:
- Start: toast/banner; show progress area.
- Progress: determinate/indeterminate; meaningful increments.
- Background: safe to navigate; header indicator + inbox entry.
- Complete: toast + deep link; OOB for long jobs.
- Fail: clear cause, retry/report, error ID.

Events (contract, not impl):
- duplication.started: id, startedAt
- duplication.progress: id, percentage?, stage?
- duplication.feedback: id, percentage?, message
- duplication.completed: id, newEpisodeId, completedAt
- duplication.failed: id, errorCode, message

Channel thresholds:
- <30s in-app only; 30s–5m optional OOB; >5m OOB by default.
Acceptance:
- Quick start visibility; regular progress; completion deep link; clear failure steps.
At a glance:
- Broadcast events keep clients up to date; send OOB on long duplication finish.

Implementation:
- Use the laravel broadcasting & notifications mechanisms 