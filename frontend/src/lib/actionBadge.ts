/**
 * The journal's pill colour for each action, shared by every screen that
 * names one, so an `edit` is the same green in the Journal and in a pass.
 *
 * Green = things that happened, amber = waiting on the operator, blue = the
 * curator talking, red = rejected.
 */
const ACTION_BADGE: Record<string, string> = {
  create: 'text-bg-success',
  edit: 'text-bg-success',
  delete: 'text-bg-success',
  restore: 'text-bg-success',
  purge: 'text-bg-success',
  import: 'text-bg-success',
  'history-forgotten': 'text-bg-success',
  approved: 'text-bg-success',
  'create-proposed': 'text-bg-warning',
  'edit-proposed': 'text-bg-warning',
  'delete-proposed': 'text-bg-warning',
  'merge-proposed': 'text-bg-warning',
  rejected: 'text-bg-danger',
  // Amber for the same reason the proposals are: work is waiting on somebody.
  // A raised flag waits on the curator, a resolved one is an answer delivered.
  'flag-raised': 'text-bg-warning',
  'flag-resolved': 'text-bg-success',
  'run-summary': 'text-bg-primary',
  observation: 'text-bg-info',
  'tooling-gap': 'text-bg-secondary',
}

export const actionBadge = (action: string): string => ACTION_BADGE[action] ?? 'text-bg-light border'
