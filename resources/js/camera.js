/**
 * Helpers de cámara seguros (12A).
 * La cámara NUNCA se abre sola: solo tras acción explícita + consentimiento UX.
 * stopCamera() cierra MediaStream tracks — preparado para scanner QR/barcode futuro.
 */

/** @type {MediaStream|null} */
let activeStream = null;

/**
 * Detiene todos los tracks del stream activo (o el pasado por argumento).
 * @param {MediaStream|null} stream
 */
export function stopCamera(stream = null) {
  const target = stream || activeStream;
  if (!target) {
    return;
  }
  try {
    target.getTracks().forEach((track) => {
      try {
        track.stop();
      } catch (_) {
        /* ignore */
      }
    });
  } finally {
    if (target === activeStream) {
      activeStream = null;
    }
  }
}

/**
 * Obtiene un MediaStream (uso futuro scanner). No llamar al cargar la app.
 * @param {MediaStreamConstraints} constraints
 * @returns {Promise<MediaStream>}
 */
export async function requestCameraStream(constraints = { video: { facingMode: { ideal: 'environment' } }, audio: false }) {
  if (!navigator.mediaDevices?.getUserMedia) {
    throw new Error('No pudimos acceder a la cámara.');
  }
  stopCamera();
  const stream = await navigator.mediaDevices.getUserMedia(constraints);
  activeStream = stream;
  return stream;
}

export function getActiveCameraStream() {
  return activeStream;
}

// Exponer para Alpine / tests smoke
if (typeof window !== 'undefined') {
  window.ARCamera = { stopCamera, requestCameraStream, getActiveCameraStream };
}
