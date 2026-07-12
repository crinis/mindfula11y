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
  activateTabFromKeydown
};
