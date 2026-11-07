/** @type {import('tailwindcss').Config} */
const token = (name) => `rgb(var(--c-${name}) / <alpha-value>)`;

module.exports = {
  darkMode: 'class',
  content: ['./app/Views/**/*.php', './app/Support/Icon.php', './public/assets/js/**/*.js'],
  theme: {
    extend: {
      // Jetons de couleur pilotés par /theme.css (palette éditable par le super admin, clair + sombre).
      colors: {
        canvas: token('canvas'),
        surface: token('surface'),
        surface2: token('surface2'),
        line: token('line'),
        ink: token('ink'),
        mute: token('mute'),
        brand: token('brand'),
        brandfg: token('brandfg'),
      },
      fontFamily: {
        sans: ['"Inter Variable"', 'Inter', 'system-ui', '-apple-system', '"Segoe UI"', 'Roboto', 'Arial', 'sans-serif'],
        display: ['"Plus Jakarta Sans Variable"', '"Inter Variable"', 'system-ui', 'sans-serif'],
        mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
      },
      borderRadius: { xl: '0.875rem', '2xl': '1.125rem' },
      boxShadow: {
        soft: '0 1px 2px rgb(0 0 0 / 0.04), 0 8px 24px -12px rgb(0 0 0 / 0.12)',
        pop: '0 12px 40px -8px rgb(0 0 0 / 0.28)',
      },
      keyframes: {
        'fade-up': { '0%': { opacity: '0', transform: 'translateY(6px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
        'pop-in': { '0%': { opacity: '0', transform: 'scale(0.97) translateY(-4px)' }, '100%': { opacity: '1', transform: 'scale(1) translateY(0)' } },
      },
      animation: { 'fade-up': 'fade-up .35s ease both', 'pop-in': 'pop-in .14s ease both' },
    },
  },
  plugins: [],
};
