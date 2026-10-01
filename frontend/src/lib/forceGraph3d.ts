import type ForceGraph3DType from '3d-force-graph'

type Loaded = {
  ForceGraph3D: typeof ForceGraph3DType
  CSS2DRenderer: typeof import('three/examples/jsm/renderers/CSS2DRenderer.js').CSS2DRenderer
  CSS2DObject: typeof import('three/examples/jsm/renderers/CSS2DRenderer.js').CSS2DObject
}

let loading: Promise<Loaded> | null = null

/** three.js and the 3D graph, in their own chunk — nothing loads them until 3D is chosen. */
export function loadForceGraph3d(): Promise<Loaded> {
  // A rejected promise is dropped rather than cached: a chunk that failed once
  // on a flaky network would otherwise fail for the rest of the session.
  loading ??= Promise.all([
    import('3d-force-graph'),
    import('three/examples/jsm/renderers/CSS2DRenderer.js'),
  ])
    .then(([graph, css2d]) => ({
      ForceGraph3D: graph.default,
      CSS2DRenderer: css2d.CSS2DRenderer,
      CSS2DObject: css2d.CSS2DObject,
    }))
    .catch((reason: unknown) => {
      loading = null
      throw reason
    })

  return loading
}
