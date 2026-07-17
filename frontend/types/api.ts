export type City = {
  id: number
  name: string
  slug: string
}

export type Chain = {
  id: number
  code: string
  name: string
}

export type Category = {
  id: number
  parent_id: number | null
  external_id: string | null
  store_type: string | null
  name: string
  level: number
  chain?: Chain
}

export type StoreOffer = {
  store_id: number
  address: string | null
  external_id: string
  latitude: number | null
  longitude: number | null
  price: number
  old_price: number | null
  unit_price: number | null
  stock: number | null
  in_stock: boolean
  discount_percent: number
  profit: number
  chain_code: string
}

export type PriceLevel = {
  count: number
  price: number
  discount_percent: number
}

export type Store = {
  id: number
  external_id: string
  name: string | null
  address: string | null
  latitude: number | null
  longitude: number | null
  is_active: boolean
  chain: Chain
  city: City | null
  last_seen_at: string | null
}

export type DiscountProduct = {
  id: number
  name: string
  image_url: string | null
  chain: Chain
  category: Category | null
  best_price: number
  old_price: number | null
  discount_percent: number
  profit: number
  unit_price: number | null
  stores_count: number
  levels: PriceLevel[]
  stores: StoreOffer[]
}

export type ListResponse<T> = {
  items: T[]
  total?: number
}

export type DiscountResponse = {
  limit: number
  offset: number
  total: number
  items: DiscountProduct[]
}

export type ProductDetailsResponse = {
  item: {
    id: number
    name: string
    image_url: string | null
    chain: Chain
    category: Pick<Category, 'id' | 'name'> | null
    best_price: number
    old_price: number | null
    discount_percent: number
    profit: number
    unit_price: number | null
    stores_count: number
    levels: PriceLevel[]
    offers: StoreOffer[]
  }
}
