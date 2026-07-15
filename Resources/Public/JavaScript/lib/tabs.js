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
export {
  activateTabFromKeydown,
  renderTabPanel,
  renderTablist
};
