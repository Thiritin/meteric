import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Billify',
  description: 'A billing engine for Laravel hosting systems: subscriptions, proration, usage metering, and a charge-vs-invoice safety model.',
  lang: 'en-US',
  base: '/billify/',
  cleanUrls: true,
  lastUpdated: true,

  head: [
    ['meta', { name: 'theme-color', content: '#3c8772' }],
  ],

  themeConfig: {
    search: {
      provider: 'local',
    },

    nav: [
      { text: 'Guide', link: '/' },
      { text: 'Usage', link: '/usage/products-and-prices' },
      { text: 'Reference', link: '/reference/facade' },
      { text: 'GitHub', link: 'https://github.com/Thiritin/billify' },
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
          { text: 'Plan changes', link: '/usage/plan-changes' },
          { text: 'Quotes and checkout', link: '/usage/quotes-and-checkout' },
          { text: 'Usage billing', link: '/usage/usage-billing' },
          { text: 'Addons and options', link: '/usage/addons-and-options' },
          { text: 'Commitments', link: '/usage/commitments' },
          { text: 'Invoicing', link: '/usage/invoicing' },
          { text: 'Tax', link: '/usage/tax' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Billify facade', link: '/reference/facade' },
          { text: 'Models', link: '/reference/models' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/Thiritin/billify' },
    ],

    editLink: {
      pattern: 'https://github.com/Thiritin/billify/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
    },
  },
})
