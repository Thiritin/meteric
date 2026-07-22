import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Meteric',
  description: 'An advanced billing engine for Laravel: subscriptions, proration, usage metering, and a charge-vs-invoice safety model.',
  lang: 'en-US',
  base: '/meteric/',
  cleanUrls: true,
  lastUpdated: true,

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/meteric/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#4f46e5' }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    search: {
      provider: 'local',
    },

    nav: [
      { text: 'Guide', link: '/' },
      { text: 'Usage', link: '/usage/products-and-prices' },
      { text: 'Reference', link: '/reference/facade' },
      { text: 'Recipes', link: '/recipes/web-hosting-company' },
      { text: 'GitHub', link: 'https://github.com/Thiritin/meteric' },
    ],

    sidebar: [
      {
        text: 'Guide',
        items: [
          { text: 'Introduction', link: '/' },
          { text: 'Requirements', link: '/guide/requirements' },
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Configuration', link: '/guide/configuration' },
          { text: 'Quickstart', link: '/guide/quickstart' },
        ],
      },
      {
        text: 'Usage',
        items: [
          { text: 'Products and prices', link: '/usage/products-and-prices' },
          { text: 'Subscriptions', link: '/usage/subscriptions' },
          { text: 'Upgrades and downgrades', link: '/usage/plan-changes' },
          { text: 'Quotes and checkout', link: '/usage/quotes-and-checkout' },
          { text: 'Orders', link: '/usage/orders' },
          { text: 'Usage billing', link: '/usage/usage-billing' },
          { text: 'Addons and options', link: '/usage/addons-and-options' },
          { text: 'Invoicing', link: '/usage/invoicing' },
          { text: 'Tax', link: '/usage/tax' },
          { text: 'Events and hooks', link: '/usage/extending' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Meteric facade', link: '/reference/facade' },
          { text: 'Models', link: '/reference/models' },
        ],
      },
      {
        text: 'Recipes',
        items: [
          { text: 'Web hosting company', link: '/recipes/web-hosting-company' },
          { text: 'Usage-based cloud', link: '/recipes/usage-based-cloud' },
          { text: 'Gameserver slots', link: '/recipes/gameserver-slots' },
          { text: 'Contract vs prepaid', link: '/recipes/contract-vs-prepaid' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/Thiritin/meteric' },
    ],

    editLink: {
      pattern: 'https://github.com/Thiritin/meteric/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
    },
  },
})
