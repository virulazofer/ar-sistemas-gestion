/**
 * Alpine factories — UI shells that must never auto-open.
 */
export function pageHelp() {
  return {
    open: false,
    openHelp() {
      this.open = true;
    },
    close() {
      this.open = false;
    },
  };
}

export function appearancePopover(initial = {}) {
  const palettes = Array.isArray(initial.palettes) ? initial.palettes : [];
  return {
    open: false,
    mode: initial.mode || 'light',
    palette: initial.palette || 'actual',
    applyPreview() {
      const root = document.documentElement;
      root.classList.toggle('dark', this.mode === 'dark');
      root.setAttribute('data-palette', this.palette);
      this.$refs.themeForm?.querySelectorAll('[data-appearance-choice]').forEach((el) => {
        const input = el.querySelector('input[type=radio]');
        if (!input) return;
        const selected = input.checked;
        el.classList.toggle('is-selected', selected);
        el.setAttribute('aria-checked', selected ? 'true' : 'false');
      });
    },
    selectMode(value) {
      this.mode = value;
      this.$nextTick(() => {
        this.applyPreview();
        this.$refs.themeForm?.requestSubmit();
      });
    },
    selectPalette(value) {
      this.palette = value;
      this.$nextTick(() => {
        this.applyPreview();
        this.$refs.themeForm?.requestSubmit();
      });
    },
    onChoiceKey(e, group, value) {
      const order = group === 'mode' ? ['light', 'dark'] : palettes;
      const idx = order.indexOf(value);
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        e.preventDefault();
        const next = order[(idx + 1) % order.length];
        group === 'mode' ? this.selectMode(next) : this.selectPalette(next);
      } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        e.preventDefault();
        const prev = order[(idx - 1 + order.length) % order.length];
        group === 'mode' ? this.selectMode(prev) : this.selectPalette(prev);
      } else if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        group === 'mode' ? this.selectMode(value) : this.selectPalette(value);
      }
    },
    close() {
      this.open = false;
    },
    toggle() {
      this.open = !this.open;
    },
  };
}
