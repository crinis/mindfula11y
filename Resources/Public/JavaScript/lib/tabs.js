import { html, nothing } from "lit";
const renderTablist = (opts) => {
  const { ariaLabel, tabs, activeTab, onSelect, onKeydown } = opts;
  return html`<div class="tabs" role="tablist" aria-label=${ariaLabel}>
        ${tabs.map((tab) => {
    const selected = activeTab === tab.id;
    return html`<button
                type="button"
                role="tab"
                id="tab-${tab.id}"
                data-tab=${tab.id}
                aria-selected=${selected ? "true" : "false"}
                aria-controls="panel-${tab.id}"
                tabindex=${selected ? "0" : "-1"}
                ?disabled=${tab.disabled ?? false}
                @click=${() => onSelect(tab.id)}
                @keydown=${onKeydown}
            >
                ${tab.label} ${tab.badge ?? nothing}
            </button>`;
  })}
    </div>`;
};
const renderTabPanel = (opts) => {
  const { tab, active, withTablist, busy, content } = opts;
  if (!withTablist) {
    return html`<div class="panel" aria-busy=${busy ? "true" : "false"}>${content}</div>`;
  }
  return html`<div
        class="panel"
        role="tabpanel"
        id="panel-${tab}"
        aria-labelledby="tab-${tab}"
        tabindex="0"
        aria-busy=${busy ? "true" : "false"}
        ?hidden=${!active}
    >
        ${content}
    </div>`;
};
async function activateTabFromKeydown(host, event, tabs, activeTab, activate) {
  const index = tabs.indexOf(activeTab);
  let next;
  switch (event.key) {
    case "ArrowRight":
      next = tabs[(index + 1) % tabs.length];
      break;
    case "ArrowLeft":
      next = tabs[(index - 1 + tabs.length) % tabs.length];
      break;
    case "Home":
      next = tabs[0];
      break;
    case "End":
      next = tabs[tabs.length - 1];
      break;
    default:
      return;
  }
  if (next === void 0) {
    return;
  }
  event.preventDefault();
  activate(next);
  await host.updateComplete;
  host.renderRoot.querySelector(`[data-tab="${next}"]`)?.focus();
}
class TabsController {
  constructor(host, tabs, initial) {
    this.host = host;
    this.tabs = tabs;
    this.handleKeydown = (event) => {
      void activateTabFromKeydown(this.host, event, this.tabs(), this.active, (tab) => this.select(tab));
    };
    this.active = initial;
    host.addController(this);
  }
  hostConnected() {
  }
  get activeTab() {
    return this.active;
  }
  /** Activates a tab and re-renders the host (click selection, findings jump). */
  select(tab) {
    this.active = tab;
    this.host.requestUpdate();
  }
  /**
   * Re-anchors the active tab when it is no longer available. Call from
   * `willUpdate` — the host is already updating, so no update is requested.
   */
  ensureActive(fallback) {
    const available = this.tabs();
    if (!available.includes(this.active)) {
      this.active = available[0] ?? fallback;
    }
  }
  /** Renders the tablist for the host-built descriptors of the current tab set. */
  renderTablist(opts) {
    return renderTablist({
      ...opts,
      activeTab: this.active,
      onSelect: (id) => this.select(id),
      onKeydown: this.handleKeydown
    });
  }
  /** Renders one panel wrapper around the host-supplied content. */
  renderPanel(opts) {
    return renderTabPanel({ ...opts, active: this.active === opts.tab });
  }
}
export {
  TabsController,
  activateTabFromKeydown,
  renderTabPanel,
  renderTablist
};
