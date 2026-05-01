const { app, BrowserWindow, Menu, ipcMain, powerSaveBlocker, dialog } = require('electron');
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');
const http = require('http');
const { autoUpdater } = require('electron-updater');

let phpServer = null;
let mainWindow = null;
let serverUrl = 'http://127.0.0.1:8000/';
let isServerStarting = false;
let windowCreated = false;
let serverCheckInProgress = false;
let powerSaveBlockerId = null;

// ─── Logging Setup ───────────────────────────────────────────────
const logDir = path.join(app.getPath('userData'), 'logs');
if (!fs.existsSync(logDir)) fs.mkdirSync(logDir, { recursive: true });

function getLogFilePath() {
  const date = new Date().toISOString().split('T')[0]; // YYYY-MM-DD
  return path.join(logDir, `applecity-${date}.log`);
}

function writeLog(entry) {
  try {
    const timestamp = new Date().toISOString().replace('T', ' ').split('.')[0];
    const line = `[${timestamp}] [${entry.level || 'INFO'}] [${entry.category || 'APP'}] ${entry.message}\n`;
    fs.appendFileSync(getLogFilePath(), line, 'utf8');
  } catch (e) {
    console.error('Failed to write log:', e);
  }
}

// IPC handler for logging from renderer
ipcMain.handle('write-log', async (event, entry) => {
  writeLog(entry);
});

// IPC handler to read logs
ipcMain.handle('read-logs', async (event, date) => {
  try {
    const logFile = date 
      ? path.join(logDir, `applecity-${date}.log`)
      : getLogFilePath();
    if (!fs.existsSync(logFile)) return [];
    const content = fs.readFileSync(logFile, 'utf8');
    return content.split('\n').filter(line => line.trim() !== '');
  } catch (e) {
    return [];
  }
});

// IPC handler to get available log dates
ipcMain.handle('get-log-dates', async () => {
  try {
    if (!fs.existsSync(logDir)) return [];
    const files = fs.readdirSync(logDir)
      .filter(f => f.startsWith('applecity-') && f.endsWith('.log'))
      .map(f => f.replace('applecity-', '').replace('.log', ''))
      .sort()
      .reverse(); // Most recent first
    return files;
  } catch (e) {
    return [];
  }
});

// Log app start
writeLog({ level: 'INFO', category: 'APP', message: 'Apple City application started' });
// ─────────────────────────────────────────────────────────────────

// ─── Auto Updater ────────────────────────────────────────────────
function setupAutoUpdater() {
  if (!app.isPackaged) return; // Only run in production

  autoUpdater.autoDownload = true;
  autoUpdater.autoInstallOnAppQuit = true;

  autoUpdater.on('checking-for-update', () => {
    writeLog({ level: 'INFO', category: 'UPDATER', message: 'Checking for updates...' });
  });

  autoUpdater.on('update-available', (info) => {
    writeLog({ level: 'INFO', category: 'UPDATER', message: `Update available: ${info.version}` });
    dialog.showMessageBox(mainWindow, {
      type: 'info',
      title: 'Update Available',
      message: `Version ${info.version} is available.`,
      detail: 'Downloading update in the background. You will be notified when it\'s ready to install.',
      buttons: ['OK']
    });
  });

  autoUpdater.on('update-not-available', () => {
    writeLog({ level: 'INFO', category: 'UPDATER', message: 'App is up to date.' });
  });

  autoUpdater.on('update-downloaded', (info) => {
    writeLog({ level: 'INFO', category: 'UPDATER', message: `Update downloaded: ${info.version}` });
    dialog.showMessageBox(mainWindow, {
      type: 'info',
      title: 'Update Ready',
      message: `Version ${info.version} has been downloaded.`,
      detail: 'The update will be installed when you restart the app. Restart now?',
      buttons: ['Restart Now', 'Later']
    }).then(result => {
      if (result.response === 0) {
        autoUpdater.quitAndInstall();
      }
    });
  });

  autoUpdater.on('error', (err) => {
    writeLog({ level: 'ERROR', category: 'UPDATER', message: `Update error: ${err.message}` });
  });

  // Check for updates 5 seconds after app is ready
  setTimeout(() => autoUpdater.checkForUpdates(), 5000);
}
// ─────────────────────────────────────────────────────────────────

function checkServerReady(callback, attempts = 0) {
  if (serverCheckInProgress) {
    console.log('Server check already in progress, skipping...');
    return;
  }
  
  serverCheckInProgress = true;
  const maxAttempts = 60; // 30 seconds max wait (increased from 15)
  
  if (attempts >= maxAttempts) {
    console.error('PHP server failed to start after 30 seconds');
    serverCheckInProgress = false;
    callback(false);
    return;
  }

  const req = http.get(serverUrl, (res) => {
    console.log(`Server check attempt ${attempts + 1}: Status ${res.statusCode}`);
    if (res.statusCode === 200) {
      // Wait a bit more to ensure server is fully ready
      setTimeout(() => {
        serverCheckInProgress = false;
        callback(true);
      }, 1000);
    } else {
      setTimeout(() => {
        serverCheckInProgress = false;
        checkServerReady(callback, attempts + 1);
      }, 500);
    }
  });

  req.on('error', (err) => {
    console.log(`Server check attempt ${attempts + 1}: Not ready yet (${err.code})`);
    setTimeout(() => {
      serverCheckInProgress = false;
      checkServerReady(callback, attempts + 1);
    }, 500);
  });

  req.setTimeout(2000, () => {
    req.destroy();
    setTimeout(() => {
      serverCheckInProgress = false;
      checkServerReady(callback, attempts + 1);
    }, 500);
  });
}

function startPhpServer() {
  if (isServerStarting) {
    console.log('Server startup already in progress, skipping...');
    return;
  }
  
  isServerStarting = true;
  
  const isDev = !app.isPackaged;
  const appDir = isDev ? path.resolve(__dirname, '..') : path.join(process.resourcesPath, 'app');

  console.log('App directory:', appDir);
  console.log('Is development:', isDev);

  // Verify app directory exists and has required files
  if (!fs.existsSync(appDir)) {
    console.error('App directory does not exist:', appDir);
    isServerStarting = false;
    return;
  }

  const indexPath = path.join(appDir, 'index.html');
  if (!fs.existsSync(indexPath)) {
    console.error('index.html not found in app directory:', indexPath);
    isServerStarting = false;
    return;
  }

  // DB path: Use the main project database for desktop executable
  // In development, use the project's data folder
  // In production (packaged), copy database to a writable location but use project data as source
  const projectDbPath = path.join(appDir, 'data', 'stocktracker.sqlite');
  let dbPath;
  
  if (isDev) {
    // Development: use project database directly
    dbPath = projectDbPath;
  } else {
    // Production: copy project database to writable location if it doesn't exist
    const userDbPath = path.join(app.getPath('userData'), 'stocktracker.sqlite');
    
    // Copy project database to user data if user database doesn't exist or is older
    if (!fs.existsSync(userDbPath) || 
        (fs.existsSync(projectDbPath) && 
         fs.statSync(projectDbPath).mtime > fs.statSync(userDbPath).mtime)) {
      
      console.log('Copying project database to user data directory...');
      try {
        // Ensure user data directory exists
        const userDataDir = path.dirname(userDbPath);
        if (!fs.existsSync(userDataDir)) {
          fs.mkdirSync(userDataDir, { recursive: true });
        }
        
        // Copy database
        fs.copyFileSync(projectDbPath, userDbPath);
        console.log('Database copied successfully');
      } catch (error) {
        console.error('Failed to copy database:', error);
        // Fallback to project database if copy fails
        dbPath = projectDbPath;
      }
    }
    
    dbPath = userDbPath;
  }
  
  const env = { ...process.env, STOCKTRACKER_DB_PATH: dbPath };

  console.log('Database path:', dbPath);

  // Use bundled PHP - always use bundled PHP for consistency
  let phpExe;
  
  if (isDev) {
    // Development: Use bundled PHP if available, otherwise system PHP
    const devBundledPhp = path.join(__dirname, 'php', 'php.exe');
    if (fs.existsSync(devBundledPhp)) {
      phpExe = devBundledPhp;
      console.log('Development: Using bundled PHP from desktop/php/');
    } else {
      // Fallback to system PHP only in development
      phpExe = 'php';
      console.log('Development: Using system PHP (bundled PHP not found)');
    }
  } else {
    // Production: ALWAYS use bundled PHP
    phpExe = process.platform === 'win32'
      ? path.join(process.resourcesPath, 'php', 'php.exe')
      : path.join(process.resourcesPath, 'php', 'php');
    console.log('Production: Using bundled PHP from resources');
  }

  console.log('Using PHP executable:', phpExe);
  console.log('PHP executable exists:', fs.existsSync(phpExe));

  // Verify PHP exists before proceeding
  if (!fs.existsSync(phpExe) && phpExe !== 'php') {
    console.error('PHP executable not found at:', phpExe);
    const { dialog } = require('electron');
    dialog.showErrorBox(
      'PHP Not Found', 
      'The bundled PHP executable was not found.\n\n' +
      'Expected location: ' + phpExe + '\n\n' +
      'Please ensure the application was installed correctly.'
    );
    isServerStarting = false;
    app.quit();
    return;
  }

  // Test PHP executable before proceeding (only in development)
  if (isDev && phpExe !== 'php') {
    const testPhp = spawn(phpExe, ['--version'], { stdio: 'pipe' });
    testPhp.on('error', (error) => {
      console.error('PHP executable test failed:', error);
      const { dialog } = require('electron');
      dialog.showErrorBox('PHP Error', 'PHP test failed: ' + error.message + '\n\nPlease ensure PHP is available.');
      isServerStarting = false;
      app.quit();
      return;
    });

    testPhp.on('close', (code) => {
      if (code !== 0) {
        console.error('PHP test failed with code:', code);
        const { dialog } = require('electron');
        dialog.showErrorBox('PHP Error', 'PHP test failed. Please check your PHP installation.');
        isServerStarting = false;
        app.quit();
        return;
      }
      console.log('PHP test successful, proceeding with server startup...');
      actuallyStartServer();
    });
  } else {
    // Production: skip PHP test and proceed directly
    console.log('Production mode: skipping PHP test, using bundled PHP...');
    actuallyStartServer();
  }

  function actuallyStartServer() {

  try {
    // Kill any existing PHP processes on port 8000
    if (process.platform === 'win32') {
      require('child_process').exec('netstat -ano | findstr :8000', (err, stdout) => {
        if (stdout) {
          console.log('Found existing processes on port 8000, attempting to start anyway...');
        }
      });
    }

    console.log('Starting PHP server with command:', phpExe, '-S', '0.0.0.0:8000');
    console.log('Working directory:', appDir);

    phpServer = spawn(phpExe, ['-S', '0.0.0.0:8000'], { 
      cwd: appDir, 
      env,
      stdio: ['pipe', 'pipe', 'pipe']
    });

    phpServer.stdout.on('data', (data) => {
      console.log('[PHP stdout]', data.toString());
    });

    phpServer.stderr.on('data', (data) => {
      const message = data.toString();
      console.log('[PHP stderr]', message);
      
      // Check for server ready message and create window only once
      if (message.includes('Development Server') && message.includes('started') && !windowCreated) {
        console.log('PHP server startup detected, creating window...');
        windowCreated = true;
        // Give the server a moment to fully initialize
        setTimeout(() => {
          createWindow();
        }, 1500);
      }
    });

    phpServer.on('error', (error) => {
      console.error('PHP server error:', error);
      isServerStarting = false;
      // Show error dialog instead of infinite restart loop
      const { dialog } = require('electron');
      dialog.showErrorBox('Server Error', 'PHP server failed to start: ' + error.message);
      app.quit();
    });

    phpServer.on('close', (code) => {
      console.log('PHP server closed with code:', code);
      if (code !== null && code !== 0 && code !== 15) { // 15 is SIGTERM (normal shutdown)
        console.log('PHP server crashed, attempting restart...');
        isServerStarting = false;
        windowCreated = false;
        setTimeout(() => startPhpServer(), 2000);
      }
    });

    // Remove the checkServerReady call since we're now using the stderr detection
    // checkServerReady((ready) => {
    //   isServerStarting = false;
    //   if (ready) {
    //     console.log('PHP server is ready, creating window...');
    //     if (!windowCreated) {
    //       windowCreated = true;
    //       createWindow();
    //     }
    //   } else {
    //     console.error('Failed to start PHP server');
    //     // Show error dialog
    //     const { dialog } = require('electron');
    //     dialog.showErrorBox('Server Error', 'Failed to start PHP server. Please ensure PHP is installed and try again.');
    //     app.quit();
    //   }
    // });

    // Fallback: if window not created after 10 seconds, try anyway
    setTimeout(() => {
      if (!windowCreated) {
        console.log('Fallback: Creating window after timeout...');
        windowCreated = true;
        createWindow();
      }
      isServerStarting = false;
    }, 10000);

  } catch (error) {
    console.error('Failed to start PHP server:', error);
    const { dialog } = require('electron');
    dialog.showErrorBox('Startup Error', 'Failed to start PHP server: ' + error.message);
    app.quit();
  }
  } // End of actuallyStartServer function
}

function stopPhpServer() {
  if (phpServer) {
    phpServer.kill();
    phpServer = null;
  }
}

function createWindow() {
  if (mainWindow) {
    console.log('Window already exists, focusing existing window...');
    mainWindow.focus();
    return;
  }

  mainWindow = new BrowserWindow({
    width: 1200,
    height: 800,
    show: false,
    title: "Apple City POS",
    icon: path.join(__dirname, '..', 'assets', 'logo.png'),
    webPreferences: { 
      nodeIntegration: false, 
      contextIsolation: true,
      preload: path.join(__dirname, 'preload.js'),
      webSecurity: false,
      allowRunningInsecureContent: true,
      backgroundThrottling: false, // CRITICAL: prevent throttling
      offscreen: false,
    }
  });

  // ── Focus Fix ─────────────────────────────────────────────────────
  mainWindow.on('focus', () => {
    mainWindow.webContents.focus();
  });
  // ──────────────────────────────────────────────────────────────────

  mainWindow.once('ready-to-show', () => {
    mainWindow.show();
    mainWindow.focus();
    mainWindow.webContents.focus();

    // Inject renderer-side fix only if inputs are actually broken
    mainWindow.webContents.executeJavaScript(`
      // One-time fix: ensure inputs are enabled on load
      document.querySelectorAll('input, textarea, select').forEach(el => {
        if (!el.disabled && el.type !== 'hidden') {
          el.style.pointerEvents = 'auto';
          el.style.userSelect = 'auto';
          el.removeAttribute('inert');
        }
      });
      console.log('Apple City: Input stability fix active');
    `).catch(err => console.error('Input fix script error:', err));
  });
  
  // Load the app directly without clearing cache on every launch
  mainWindow.loadURL(serverUrl);
  
  mainWindow.webContents.on('did-finish-load', () => {
    console.log('Window loaded successfully');
    // Only show the window on first load, don't steal focus on subsequent navigations
    if (!mainWindow.isVisible()) {
      mainWindow.show();
      mainWindow.focus();
      mainWindow.webContents.focus();
    }
  });

  mainWindow.webContents.on('did-fail-load', (event, errorCode, errorDescription) => {
    console.error('Failed to load:', errorCode, errorDescription);
    // Only retry if it's a connection error and server might be starting
    if (errorCode === -102 && !windowCreated) { // ERR_CONNECTION_REFUSED
      setTimeout(() => {
        console.log('Retrying page load...');
        if (mainWindow && !mainWindow.isDestroyed()) {
          const retryUrl = `${serverUrl}?retry=${Date.now()}`;
          mainWindow.loadURL(retryUrl);
        }
      }, 2000);
    }
  });

  mainWindow.webContents.on('console-message', (event, level, message, line, sourceId) => {
    console.log('Console:', message);
  });

  // Add keyboard shortcut for refresh (Ctrl+R or F5)
  mainWindow.webContents.on('before-input-event', (event, input) => {
    if ((input.control && input.key.toLowerCase() === 'r') || input.key === 'F5') {
      mainWindow.webContents.reload();
    }
  });

  mainWindow.on('closed', () => {
    stopPhpServer();
    mainWindow = null;
    windowCreated = false;
  });

  // Open DevTools in development — delayed so window focus is established first
  if (!app.isPackaged) {
    mainWindow.webContents.once('did-finish-load', () => {
      setTimeout(() => mainWindow.webContents.openDevTools({ mode: 'detach' }), 1000);
    });
  }
}

// IPC handler for printing
ipcMain.handle('print-receipt', async (event, htmlContent) => {
  const printWindow = new BrowserWindow({
    width: 800,
    height: 600,
    show: false,
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true
    }
  });

  // Load the HTML content
  await printWindow.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(htmlContent));
  
  // Wait for content to be ready
  await new Promise(resolve => setTimeout(resolve, 500));
  
  return new Promise((resolve) => {
    printWindow.webContents.print({
      silent: false,
      printBackground: true,
      color: true,
      margins: {
        marginType: 'none'
      },
      pageSize: {
        width: 80000, // 80mm in microns
        height: 297000 // A4 height, will auto-adjust
      }
    }, (success, errorType) => {
      if (!success) {
        console.log('Print cancelled or failed:', errorType);
        // Don't reject - just resolve with false so no error is shown
        resolve(false);
      } else {
        resolve(true);
      }
      printWindow.close();
    });
  });
});

app.whenReady().then(() => {
  console.log('Electron app ready, starting PHP server...');
  
  // CRITICAL: Prevent system from throttling the app
  powerSaveBlockerId = powerSaveBlocker.start('prevent-app-suspension');
  console.log('Power save blocker started:', powerSaveBlocker.isStarted(powerSaveBlockerId));

  setupAutoUpdater();
  
  // Create application menu with refresh option
  const template = [
    {
      label: 'File',
      submenu: [
        {
          label: 'Refresh',
          accelerator: 'CmdOrCtrl+R',
          click: () => {
            if (mainWindow && !mainWindow.isDestroyed()) {
              mainWindow.webContents.reload();
            }
          }
        },
        {
          label: 'Force Reload',
          accelerator: 'CmdOrCtrl+Shift+R',
          click: () => {
            if (mainWindow && !mainWindow.isDestroyed()) {
              console.log('Force reload requested...');
              mainWindow.webContents.reloadIgnoringCache();
            }
          }
        },
        { type: 'separator' },
        {
          label: 'Quit',
          accelerator: process.platform === 'darwin' ? 'Cmd+Q' : 'Ctrl+Q',
          click: () => {
            app.quit();
          }
        }
      ]
    },
    {
      label: 'View',
      submenu: [
        { role: 'reload' },
        { role: 'forceReload' },
        { role: 'toggleDevTools' },
        { type: 'separator' },
        { role: 'resetZoom' },
        { role: 'zoomIn' },
        { role: 'zoomOut' },
        { type: 'separator' },
        { role: 'togglefullscreen' }
      ]
    },
    {
      label: 'Help',
      submenu: [
        {
          label: 'About Apple City POS',
          click: () => {
            const { dialog } = require('electron');
            dialog.showMessageBox(mainWindow, {
              type: 'info',
              title: 'About Apple City POS',
              message: 'Apple City POS',
              detail: 
                'Version: 1.0.0\n\n' +
                'iPhone Sales, Repairs & Service Management\n\n' +
                'Apple City POS is a smart retail management system that helps businesses track stock, monitor sales, and prevent losses from expired products — all in one powerful offline desktop solution.\n\n' +
                'Authorised iPhone Dealer\n\n' +
                '© 2026 Apple City. All rights reserved.',
              buttons: ['OK']
            });
          }
        }
      ]
    }
  ];
  const menu = Menu.buildFromTemplate(template);
  Menu.setApplicationMenu(menu);
  
  startPhpServer();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0 && !isServerStarting) {
      if (phpServer) {
        // Server is running but no window, just create window
        if (!windowCreated) {
          windowCreated = true;
          createWindow();
        }
      } else {
        // No server running, start everything
        startPhpServer();
      }
    }
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    // Stop power save blocker
    if (powerSaveBlockerId !== null && powerSaveBlocker.isStarted(powerSaveBlockerId)) {
      powerSaveBlocker.stop(powerSaveBlockerId);
      console.log('Power save blocker stopped');
    }
    writeLog({ level: 'INFO', category: 'APP', message: 'Apple City application closed' });
    stopPhpServer();
    isServerStarting = false;
    windowCreated = false;
    app.quit();
  }
});
