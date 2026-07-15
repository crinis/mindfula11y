import { html } from "lit";
class LiveAnnouncer {
  constructor(host) {
    this.message = "";
    this.host = host;
  }
  async announce(message, signal) {
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
