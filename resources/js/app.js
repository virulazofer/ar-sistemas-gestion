
import Alpine from 'alpinejs';
import { stopCamera, requestCameraStream, getActiveCameraStream } from './camera';
import { registerPwa, initOfflineBanner } from './pwa';
import { documentCaptureApp } from './document-capture';

window.Alpine = Alpine;
window.ARCamera = { stopCamera, requestCameraStream, getActiveCameraStream };
window.documentCaptureApp = documentCaptureApp;

Alpine.start();

registerPwa();
initOfflineBanner();
