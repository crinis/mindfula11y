import { mergeAnalyses } from "../../lib/structure/analysis.js";
import { applyRecordMetadata, collectRecordRequests } from "../../lib/structure/enrichment.js";
import { StructureAnalysisError } from "../../lib/structure/error.js";
import { StructureAnalysisApi } from "./api.js";
import { RenderedPageLoader } from "./page-loader.js";
class StructureAnalysisCoordinator {
  constructor(backend, loader) {
    this.backend = backend;
    this.loader = loader;
  }
  /**
   * Wires the coordinator to the real backend: a single `StructureAnalysisApi`
   * instance serves both the loader's ticket issuance and the coordinator's
   * own record-metadata fetch, so it cannot be expressed as a constructor
   * default without widening `backend` back to the full API surface.
   */
  static createDefault() {
    const api = new StructureAnalysisApi();
    return new StructureAnalysisCoordinator(api, new RenderedPageLoader(api));
  }
  async analyze(options, parent, signal) {
    const load = (viewport) => this.loader.load(viewport, parent, signal, options);
    const [mobile, desktop] = await Promise.all([load("mobile"), load("desktop")]);
    const analysis = {
      headings: this.mergeDomain(options.headings, mobile.headings, desktop.headings),
      landmarks: this.mergeDomain(options.landmarks, mobile.landmarks, desktop.landmarks)
    };
    const requests = collectRecordRequests(analysis);
    const metadata = await this.backend.fetchRecordMetadata(requests, signal);
    applyRecordMetadata(analysis, metadata);
    return analysis;
  }
  /** Merges one domain's viewport pair, or yields null when that domain is disabled. */
  mergeDomain(include, mobile, desktop) {
    if (!include) {
      return null;
    }
    if (mobile === null || desktop === null) {
      throw new StructureAnalysisError(
        "payload",
        "The rendered page did not return the requested analysis results."
      );
    }
    return mergeAnalyses({ mobile, desktop });
  }
}
export {
  StructureAnalysisCoordinator
};
