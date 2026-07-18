import { isScanInProgress } from "./types.js";
const POLL_DELAY_MS = 5e3;
class ScanSessionController {
  constructor(host, options) {
    this.host = host;
    this.options = options;
    this._state = "initial";
    this._error = null;
    this._result = null;
    this._crawlResult = null;
    /** The attribute scan id suppressed after a user-triggered create (old `invalidScanId`). */
    this.dismissedAttributeScanId = "";
    /** The id of a scan this controller created; takes precedence over the attribute id. */
    this.createdScanId = "";
    this.lastStatus = "";
    this.autoCreateAttempted = false;
    /** Marks the next settled load as the "started" transition of a fresh create. */
    this.justCreated = false;
    this.connected = false;
    this.initialized = false;
    this.abortController = null;
    host.addController(this);
  }
  get state() {
    return this._state;
  }
  get error() {
    return this._error;
  }
  /** Last successful load; stays mounted while a re-poll runs. */
  get result() {
    return this._result;
  }
  get crawlResult() {
    return this._crawlResult;
  }
  /** Attribute id unless it was created away from or dismissed by a user-triggered create. */
  effectiveScanId() {
    if (this.createdScanId !== "") {
      return this.createdScanId;
    }
    const scanId = this.options.scanId();
    return scanId !== this.dismissedAttributeScanId ? scanId : "";
  }
  hostConnected() {
    this.connected = true;
    if (!this.initialized) {
      this.initialized = true;
      this.start();
      return;
    }
    if (this._state === "loading") {
      this.start();
      return;
    }
    if (this.lastStatus !== "" && isScanInProgress(this.lastStatus)) {
      this.schedulePoll();
    }
  }
  hostDisconnected() {
    this.connected = false;
    this.clearPoll();
    this.abortController?.abort();
    this.abortController = null;
  }
  /** Idempotent load of the current scan — never creates. */
  async reload() {
    const scanId = this.effectiveScanId();
    if (scanId === "") {
      this._result = null;
      this._crawlResult = null;
      this.lastStatus = "";
      this.setState("initial");
      return;
    }
    const signal = this.beginOperation();
    this.setState("loading");
    try {
      const filtered = await this.options.service.loadScan(scanId, this.options.pageUrlFilter(), signal);
      if (signal.aborted) {
        return;
      }
      if (filtered === null) {
        this.forgetScan(scanId);
        return;
      }
      let crawl = null;
      if (this.options.withCrawlResult?.() === true && filtered.mode === "crawl") {
        const unfiltered = await this.options.service.loadScan(scanId, [], signal);
        if (signal.aborted) {
          return;
        }
        crawl = unfiltered;
      }
      this._result = filtered;
      this._crawlResult = crawl !== null && crawl.mode === "crawl" ? crawl : null;
      this.setState("ready");
      this.commitStatus(filtered);
    } catch (error) {
      if (signal.aborted) {
        return;
      }
      this._error = error;
      this.setState("error");
      if (this.lastStatus !== "" && isScanInProgress(this.lastStatus)) {
        this.schedulePoll();
      }
    }
  }
  /**
   * Explicit create (manual trigger). Suppresses the attribute id, then loads
   * the new scan. Rethrows a create failure so the host can surface it; the
   * AI audit rides alongside as an editor choice (never for auto-create).
   */
  async createScan(demand, aiAudit = false) {
    const signal = this.beginOperation();
    let created;
    try {
      created = await this.options.service.createScan(demand, aiAudit, signal);
    } catch (error) {
      if (signal.aborted) {
        return;
      }
      throw error;
    }
    if (signal.aborted) {
      return;
    }
    this.dismissedAttributeScanId = this.options.scanId();
    this.adoptCreatedScan(created);
    void this.reload();
  }
  /** Cancels the running scan, then reloads to reflect the final state. Rethrows a real failure. */
  async cancelScan() {
    const scanId = this.effectiveScanId();
    if (scanId === "") {
      return;
    }
    const signal = this.beginOperation();
    let failure = null;
    try {
      await this.options.service.cancelScan(scanId, signal);
    } catch (error) {
      if (signal.aborted) {
        return;
      }
      failure = error;
    }
    if (this.connected) {
      void this.reload();
    }
    if (failure !== null) {
      throw failure;
    }
  }
  /** Initial entry: load an existing scan, otherwise attempt the auto-create. */
  start() {
    if (!this.connected) {
      return;
    }
    if (this.effectiveScanId() !== "") {
      void this.reload();
    } else {
      void this.ensureScan();
    }
  }
  /** Auto-creates the initial scan at most once — never with the AI audit (cost). */
  async ensureScan() {
    const demand = this.options.demand();
    if (demand === null || this.autoCreateAttempted) {
      this._result = null;
      this._crawlResult = null;
      this.setState("initial");
      return;
    }
    this.autoCreateAttempted = true;
    const signal = this.beginOperation();
    this.setState("loading");
    let created;
    try {
      created = await this.options.service.createScan(demand, false, signal);
    } catch (error) {
      if (signal.aborted) {
        return;
      }
      this._error = error;
      this.setState("error");
      return;
    }
    if (signal.aborted) {
      return;
    }
    this.adoptCreatedScan(created);
    void this.reload();
  }
  /** Shared state update after any successful create (auto or explicit). */
  adoptCreatedScan(created) {
    this.createdScanId = created.scanId;
    this.lastStatus = created.status;
    this.justCreated = true;
    this._result = null;
    this._crawlResult = null;
    this.host.requestUpdate();
  }
  /**
   * Scan gone on the API side — forget the id (auto-create, when enabled,
   * recreates it) and re-run so the now-effective id (or the auto-create) is
   * picked up. The id changes toward '' and auto-create is guarded to once,
   * so this cannot loop.
   */
  forgetScan(scanId) {
    if (this.createdScanId === scanId) {
      this.createdScanId = "";
    } else {
      this.dismissedAttributeScanId = scanId;
    }
    this._result = null;
    this._crawlResult = null;
    this.lastStatus = "";
    this.setState("initial");
    this.start();
  }
  /** Detects the transition, re-arms the poll while in progress, and notifies the host. */
  commitStatus(result) {
    const previous = this.lastStatus;
    if (isScanInProgress(result.status)) {
      this.schedulePoll();
    }
    if (this.justCreated) {
      this.justCreated = false;
      this.lastStatus = result.status;
      this.options.onTransition?.(null, result);
      return;
    }
    const wasInProgress = previous !== "" && isScanInProgress(previous);
    this.lastStatus = result.status;
    if (wasInProgress && !isScanInProgress(result.status)) {
      this.options.onTransition?.(previous, result);
    }
  }
  schedulePoll() {
    this.clearPoll();
    this.pollTimer = window.setTimeout(() => {
      this.pollTimer = void 0;
      void this.reload();
    }, POLL_DELAY_MS);
  }
  clearPoll() {
    if (this.pollTimer !== void 0) {
      window.clearTimeout(this.pollTimer);
      this.pollTimer = void 0;
    }
  }
  beginOperation() {
    this.clearPoll();
    this.abortController?.abort();
    this.abortController = new AbortController();
    return this.abortController.signal;
  }
  setState(state) {
    this._state = state;
    if (state !== "error") {
      this._error = null;
    }
    this.host.requestUpdate();
  }
}
export {
  ScanSessionController
};
