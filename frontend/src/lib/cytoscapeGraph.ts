import type { Core, CytoscapeOptions } from 'cytoscape'

let loading: Promise<(options: CytoscapeOptions) => Core> | null = null

/**
 * Cytoscape and the fCoSE layout, loaded once for the whole app.
 *
 * `cytoscape.use()` registers on the MODULE, so a flag inside a component's
 * setup is per instance and re-registers the extension on every mount.
 */
export function loadCytoscape(): Promise<(options: CytoscapeOptions) => Core> {
  loading ??= Promise.all([import('cytoscape'), import('cytoscape-fcose')]).then(
    ([{ default: cytoscape }, { default: fcose }]) => {
      cytoscape.use(fcose)

      return cytoscape
    },
  )

  return loading
}
