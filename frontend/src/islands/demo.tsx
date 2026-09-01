import { Button } from '@/ui/button'

/**
 * Throwaway island used only to prove the PHP-mount → React-hydrate → shared-UI
 * pipeline end to end. Delete once the first real module (toast-notices) lands.
 */
export function Demo({ label = 'Galaxie UI' }: { label?: string }) {
  return (
    <div className="flex items-center gap-3 rounded-lg border border-border bg-card p-4">
      <span className="text-sm text-muted-foreground">{label}</span>
      <Button>Primary</Button>
      <Button variant="outline">Outline</Button>
    </div>
  )
}
