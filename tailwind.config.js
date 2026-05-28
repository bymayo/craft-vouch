/** @type {import('tailwindcss').Config} */
module.exports = {
  // Every utility is prefixed so nothing collides with Craft's own
  // control-panel styles, e.g. `vouch-flex`, `vouch-gap-2`.
  prefix: 'vouch-',
  // We render inside Craft's CP, which already ships its own reset. Tailwind's
  // Preflight would clobber those base styles, so it stays off - we only want
  // the utility layer.
  corePlugins: {
    preflight: false,
  },
  content: ['./src/templates/**/*.twig'],
  theme: {
    extend: {},
  },
  plugins: [],
};
