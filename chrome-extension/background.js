// Restore sidebar pinned state on install and browser startup
async function restorePanelBehavior() {
  const { sidebarMode } = await chrome.storage.sync.get(['sidebarMode']);
  await chrome.sidePanel.setPanelBehavior({
    openPanelOnActionClick: !!sidebarMode,
  });
}

chrome.runtime.onInstalled.addListener(restorePanelBehavior);
chrome.runtime.onStartup.addListener(restorePanelBehavior);
