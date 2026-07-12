import { html } from "lit";
class LiveAnnouncer {
  constructor(host) {
    this.message = "";
    this.host = host;
  }
  async announce(message) {
    this.message = "";
    this.host.requestUpdate();
    await this.host.updateComplete;
    this.message = message;
    this.host.requestUpdate();
  }
  /** The visually hidden live region carrying the current announcement. */
  render() {
    return html`<div class="sr-only" role="status"><span>${this.message}</span></div>`;
  }
}
export {
  LiveAnnouncer
};
