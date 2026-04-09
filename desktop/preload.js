const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  printReceipt: (htmlContent) => ipcRenderer.invoke('print-receipt', htmlContent),
  writeLog: (entry) => ipcRenderer.invoke('write-log', entry),
  readLogs: (date) => ipcRenderer.invoke('read-logs', date),
  getLogDates: () => ipcRenderer.invoke('get-log-dates')
});
