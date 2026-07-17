import { html } from "lit";
class LiveAnnouncer {
  constructor(host) {
    this.message = "";
    /** Tail of the announcement chain — never rejects (see announce()). */
    this.pending = Promise.resolve();
    this.host = host;
  }
  /**
   * Announcements are serialized: an overlapping call waits for the previous
   * clear/set double-render to complete, otherwise it would wipe the earlier
   * message before assistive technology picks it up.
   */
  announce(message, signal) {
    const run = this.pending.then(() => this.performAnnounce(message, signal));
    this.pending = run.then(
      () => void 0,
      () => void 0
    );
    return run;
  }
  async performAnnounce(message, signal) {
    signal?.throwIfAborted();
    this.message = "";
    this.host.requestUpdate();
    await this.host.updateComplete;
    signal?.throwIfAborted();
    this.message = message;
    this.host.requestUpdate();
    await this.host.updateComplete;
    signal?.throwIfAborted();
  }
  /** The visually hidden live region carrying the current announcement. */
  render() {
    return html`<div class="sr-only" role="status"><span>${this.message}</span></div>`;
  }
}
export {
  LiveAnnouncer
};
