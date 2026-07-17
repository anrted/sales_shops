import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ProductCard from './ProductCard.vue'

describe('ProductCard', () => {
  it('shows best price and stores count', () => {
    const wrapper = mount(ProductCard, {
      props: {
        product: {
          id: 1,
          name: 'Молоко',
          image_url: null,
          chain: { id: 1, code: 'magnit', name: 'Магнит' },
          category: null,
          best_price: 99,
          old_price: 150,
          discount_percent: 34,
          profit: 51,
          unit_price: null,
          stores_count: 2,
          levels: [],
          stores: []
        }
      }
    })

    expect(wrapper.text()).toContain('Молоко')
    expect(wrapper.text()).toContain('Магнит')
    expect(wrapper.text()).toContain('2 магазина')
  })
})
