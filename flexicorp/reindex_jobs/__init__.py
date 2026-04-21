"""Background reindex job queue (filesystem-backed); used by CLI and TEITOK."""

from .store import JobState, jobs_dir, read_job, write_job

__all__ = ["JobState", "jobs_dir", "read_job", "write_job"]
