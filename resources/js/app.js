
import Alpine from 'alpinejs';
import { stopCamera, requestCameraStream, getActiveCameraStream } from './camera';
import { registerPwa, initOfflineBanner } from './pwa';
import { documentCaptureApp } from './document-capture';
import { pageHelp, appearancePopover } from './ui-shell';

window.Alpine = Alpine;
window.ARCamera = { stopCamera, requestCameraStream, getActiveCameraStream };
window.documentCaptureApp = documentCaptureApp;
window.pageHelp = pageHelp;
window.appearancePopover = appearancePopover;

Alpine.data('pageHelp', pageHelp);
Alpine.data('appearancePopover', appearancePopover);

// Alpine primero: la UI (ayuda/apariencia/drawer) no debe quedar colgada si falla PWA.
Alpine.start();

try {
  registerPwa();
  initOfflineBanner();
} catch (_) {
  /* PWA opcional */
}
