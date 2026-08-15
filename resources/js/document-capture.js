/**
 * Captura documental: consentimiento → input file/camera → preview → confirmar.
 * No activa cámara al abrir la página.
 */

const ALLOWED = new Set(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

export function documentCaptureApp(config = {}) {
  const maxBytes = (config.maxUploadKb || 10240) * 1024;

  return {
    consentOpen: false,
    pendingMode: null, // 'camera' | 'file'
    previewUrl: null,
    previewKind: null, // image|pdf
    fileName: null,
    clientError: null,
    keepOriginal: false,

    init() {
      // Garantía: no hay getUserMedia aquí ni al montar.
      window.addEventListener('pagehide', () => this.revokePreview());
      window.addEventListener('beforeunload', () => {
        if (window.ARCamera?.stopCamera) {
          window.ARCamera.stopCamera();
        }
      });
    },

    askConsent(mode) {
      this.clientError = null;
      this.pendingMode = mode;
      this.consentOpen = true;
    },

    cancelConsent() {
      this.consentOpen = false;
      this.pendingMode = null;
    },

    continueAfterConsent() {
      const mode = this.pendingMode;
      this.consentOpen = false;
      this.pendingMode = null;
      this.$nextTick(() => {
        if (mode === 'camera') {
          this.$refs.cameraInput?.click();
        } else if (mode === 'file') {
          this.$refs.fileInput?.click();
        }
      });
    },

    onFilePicked(event) {
      this.clientError = null;
      const file = event.target.files?.[0];
      if (!file) {
        return;
      }

      if (String(file.type || '').startsWith('video/')) {
        this.clientError = 'No se admiten videos.';
        this.resetInputs();
        return;
      }

      if (file.type && !ALLOWED.has(file.type) && !file.type.startsWith('image/')) {
        this.clientError = 'Formato no admitido.';
        this.resetInputs();
        return;
      }

      if (file.size > maxBytes) {
        this.clientError = 'El archivo supera el tamaño permitido.';
        this.resetInputs();
        return;
      }

      this.revokePreview();
      this.fileName = file.name;
      this.previewKind = file.type === 'application/pdf' ? 'pdf' : 'image';
      this.previewUrl = URL.createObjectURL(file);

      // Mover el File al input de submit oculto
      const dt = new DataTransfer();
      dt.items.add(file);
      if (this.$refs.submitFile) {
        this.$refs.submitFile.files = dt.files;
      }
    },

    repeatCapture() {
      this.revokePreview();
      this.resetInputs();
      this.askConsent('camera');
    },

    cancelPreview() {
      this.revokePreview();
      this.resetInputs();
      if (window.ARCamera?.stopCamera) {
        window.ARCamera.stopCamera();
      }
    },

    revokePreview() {
      if (this.previewUrl) {
        URL.revokeObjectURL(this.previewUrl);
      }
      this.previewUrl = null;
      this.previewKind = null;
      this.fileName = null;
    },

    resetInputs() {
      if (this.$refs.cameraInput) this.$refs.cameraInput.value = '';
      if (this.$refs.fileInput) this.$refs.fileInput.value = '';
      if (this.$refs.submitFile) this.$refs.submitFile.value = '';
    },
  };
}

if (typeof window !== 'undefined') {
  window.documentCaptureApp = documentCaptureApp;
}
