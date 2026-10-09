import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

/** The repository the workflow operates on (overridable so the self-test can use a throwaway repo). */
export const PROJECT_ROOT = process.env.FLOWSYNC_PROJECT_ROOT
  ? path.resolve(process.env.FLOWSYNC_PROJECT_ROOT)
  : path.resolve(here, '../../..');

export const WORKFLOW_DIR = path.join(PROJECT_ROOT, '.agents/workflow');
export const STATE_FILE = path.join(WORKFLOW_DIR, 'state.json');
export const EVENTS_FILE = path.join(WORKFLOW_DIR, 'events.jsonl');
export const TASKS_DIR = path.join(WORKFLOW_DIR, 'tasks');
export const REPORTS_DIR = path.join(WORKFLOW_DIR, 'reports');
export const LOCKS_DIR = path.join(WORKFLOW_DIR, 'locks');
export const WORKTREES_DIR = path.join(WORKFLOW_DIR, 'worktrees');
export const PROGRESS_FILE = path.join(WORKFLOW_DIR, 'PROGRESS.md');
export const MCP_SERVER_ENTRY = path.resolve(here, '../mcp-server/index.js');

/** A task id doubles as a branch suffix and a directory name, so it is restricted hard. */
export const TASK_ID_RE = /^[a-z0-9][a-z0-9-]{1,47}$/;
