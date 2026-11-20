import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { longDate } from '../../format'

type Generic = { code: string; name: string; form: string | null; route: string | null; composition?: string | null }
type Brand = { code: string; name: string; product_name: string | null; supplier: string | null; generic: Generic | null; composition: string | null }
type Linked = Generic & { therapeutic_role?: string | null; indication?: string | null; contra_indication?: string | null; interactions?: string | null }
type Item = {
  id: number
  name: string
  type: string | null
  manufacturer: string | null
  composition: string | null
  units_in_stock: number
  ref_brand_code: string | null
  ref_generic_code: string | null
  brand: Brand | null
  generic: Linked | null
}
type Status = { brands: number; generics: number; latest: string | null; medicines: number; linked: number }
type Next = { remaining: number; medicine: Item | null; suggestions: Brand[]; generics: Generic[] }

/**
 * Linking the pharmacy's medicines to the national drug list (Common Drug Codes for India, from the Government's NRCeS).
 * One medicine at a time, the list's likeliest brands are shown; whoever knows the stock picks the right one, and only
 * then is what it contains filled in from the list. A medicine whose brand the list lacks can be linked to its generic.
 */
export function PharmacyNationalList() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [params, setParams] = useSearchParams()
  const opened = Number(params.get('medicine')) || null
  const [status, setStatus] = useState<Status | null>(null)
  const [all, setAll] = useState(false)
  const [item, setItem] = useState<Item | null>(null)
  const [suggestions, setSuggestions] = useState<Brand[]>([])
  const [likelyGenerics, setLikelyGenerics] = useState<Generic[]>([])
  const [remaining, setRemaining] = useState<number | null>(null)
  const [query, setQuery] = useState('')
  const [found, setFound] = useState<{ brands: Brand[]; generics: Generic[] } | null>(null)
  const [useComposition, setUseComposition] = useState(true)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  const loadStatus = useCallback(() => api.get<Status>('/pharmacy/national-list').then((res) => setStatus(res.data)), [])
  const show = (medicine: Item | null, list: Brand[], generics: Generic[] = []) => {
    setItem(medicine)
    setSuggestions(list)
    setLikelyGenerics(generics)
    setQuery('')
    setFound(null)
    setUseComposition(true)
    setLoading(false)
  }
  const next = useCallback(
    (after: Item | null) =>
      api
        .get<Next>('/pharmacy/national-list/next', { params: { ...(after ? { after_name: after.name, after_id: after.id } : {}), ...(all ? { all: 1 } : {}) } })
        .then((res) => {
          setRemaining(res.data.remaining)
          show(res.data.medicine, res.data.suggestions, res.data.generics)
        })
        .catch(() => setError('Could not load the next medicine. Check the connection and try again.')),
    [all],
  )

  useEffect(() => {
    loadStatus().catch(() => setError('Could not load the national drug list.'))
  }, [loadStatus])
  useEffect(() => {
    if (opened) {
      api
        .get<{ medicine: Item; suggestions: Brand[]; generics: Generic[] }>(`/pharmacy/national-list/medicines/${opened}`)
        .then((res) => show(res.data.medicine, res.data.suggestions, res.data.generics))
        .catch(() => setError('Could not open that medicine.'))
    } else {
      void next(null)
    }
  }, [opened, next])

  const search = (e: FormEvent) => {
    e.preventDefault()
    if (query.trim().length < 2) return
    api
      .get<{ brands: Brand[]; generics: Generic[] }>('/pharmacy/national-list/search', { params: { q: query.trim() } })
      .then((res) => setFound(res.data))
      .catch(() => setError('Could not search the list.'))
  }

  const link = async (choice: { brand_code: string } | { generic_code: string }, label: string) => {
    if (!item) return
    setBusy(true)
    setError('')
    try {
      const res = await api.post<Item>(`/pharmacy/medicines/${item.id}/national-link`, { ...choice, use_composition: useComposition })
      setNotice(`${item.name} is linked to ${label}.${res.data.composition && res.data.composition !== item.composition ? ` It contains ${res.data.composition}.` : ''}`)
      void loadStatus()
      if (opened) show(res.data, [])
      else await next(res.data)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not link it.')
    } finally {
      setBusy(false)
    }
  }

  const unlink = async () => {
    if (!item) return
    setBusy(true)
    try {
      const res = await api.delete<Item>(`/pharmacy/medicines/${item.id}/national-link`)
      setNotice(`The link for ${item.name} is taken off. What it contains is left as it was.`)
      show(res.data, suggestions, likelyGenerics)
      void loadStatus()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not take the link off.')
    } finally {
      setBusy(false)
    }
  }

  const choose = (brand: Brand) =>
    canEdit && (
      <button type="button" className="btn primary small-btn" disabled={busy} onClick={() => link({ brand_code: brand.code }, brand.name)}>
        This one
      </button>
    )
  const brandRow = (brand: Brand) => (
    <li key={brand.code} className="ref-choice">
      <div className="grow">
        <strong>{brand.name}</strong>
        <small className="muted block">{[brand.supplier, brand.generic?.name].filter(Boolean).join(' · ')}</small>
        {brand.composition && <small className="block">Contains {brand.composition}</small>}
      </div>
      {choose(brand)}
    </li>
  )
  const genericRow = (g: Generic) => (
    <li key={g.code} className="ref-choice">
      <div className="grow">
        <strong>{g.name}</strong>
        {g.composition && <small className="block">Contains {g.composition}</small>}
      </div>
      {canEdit && (
        <button type="button" className="btn primary small-btn" disabled={busy} onClick={() => link({ generic_code: g.code }, g.name)}>
          This one
        </button>
      )}
    </li>
  )
  const linked = Boolean(item?.ref_brand_code || item?.ref_generic_code)
  const differs = (composition: string | null | undefined) => Boolean(item?.composition && composition && item.composition !== composition)

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <h1 className="grow">National drug list</h1>
          {opened ? (
            <button className="btn ghost" onClick={() => setParams({})}>
              Next unlinked medicine
            </button>
          ) : (
            <label className="check">
              <input type="checkbox" checked={all} onChange={(e) => setAll(e.target.checked)} /> Include medicines not in stock
            </label>
          )}
          <Link className="btn ghost" to="/pharmacy/medicines">
            Medicines
          </Link>
        </div>
        {status && (
          <p className="muted">
            {status.brands.toLocaleString('en-IN')} brands and {status.generics.toLocaleString('en-IN')} generics from the Government&apos;s Common Drug Codes for India
            {status.latest ? `, entries up to ${longDate(status.latest)}` : ''}. <strong>{status.linked}</strong> of {status.medicines} of our medicines are linked
            {remaining !== null && !opened ? `; ${remaining} ${all ? '' : 'in stock '}still to do` : ''}.
          </p>
        )}
        {status && status.brands === 0 && <p className="form-error">The national drug list has not been loaded yet.</p>}
        {notice && <p className="notice">{notice}</p>}
        {error && <p className="form-error">{error}</p>}

        {loading && !item && <p className="loading">Loading…</p>}
        {!loading && !item && !opened && <p className="notice">Every {all ? '' : 'in-stock '}medicine has been linked or looked at. {all ? '' : 'Tick "Include medicines not in stock" to go through the rest.'}</p>}

        {item && (
          <div className="ref-medicine">
            <h2>
              {item.name} {linked && <span className="pill ok">Linked</span>}
            </h2>
            <p className="muted">
              {[item.type, item.manufacturer, `${item.units_in_stock} in stock`].filter(Boolean).join(' · ')}
              <br />
              {item.composition ? `Contains ${item.composition}` : 'What it contains is not filled in yet.'}
            </p>

            {linked ? (
              <div className="ref-linked">
                <p>
                  <strong>On the list as:</strong> {item.brand?.name ?? 'its generic only'}
                  {item.brand?.supplier ? ` · ${item.brand.supplier}` : ''}
                  <br />
                  <strong>Generic:</strong> {item.generic?.name ?? '—'}
                </p>
                {item.generic?.indication && <p><strong>Used for:</strong> {item.generic.indication}</p>}
                {item.generic?.contra_indication && <p><strong>Not to be used:</strong> {item.generic.contra_indication}</p>}
                {item.generic?.interactions && <p><strong>Interactions:</strong> {item.generic.interactions}</p>}
                {canEdit && (
                  <button type="button" className="btn ghost danger" disabled={busy} onClick={unlink}>
                    Take the link off
                  </button>
                )}
              </div>
            ) : (
              <>
                {suggestions.length > 0 && <h3>Most likely</h3>}
                {suggestions.length > 0 && <ul className="ref-choices">{suggestions.map(brandRow)}</ul>}
                {likelyGenerics.length > 0 && (
                  <>
                    <h3>{suggestions.length ? 'Or, if the brand is not here, what it is' : 'What it is'}</h3>
                    <ul className="ref-choices">{likelyGenerics.map(genericRow)}</ul>
                  </>
                )}
                {suggestions.length === 0 && likelyGenerics.length === 0 && <p className="muted small">The list has nothing with a name like this. Search below, or skip it.</p>}

                {(differs(suggestions[0]?.composition) || item.composition) && canEdit && (
                  <label className="check">
                    <input type="checkbox" checked={useComposition} onChange={(e) => setUseComposition(e.target.checked)} /> Write what it contains from the national list (replaces &quot;{item.composition}&quot;)
                  </label>
                )}

                <form className="toolbar gap-top" onSubmit={search}>
                  <input className="search grow" type="search" placeholder="Not here? Search a brand, or a drug and strength (azithromycin 500)" value={query} onChange={(e) => setQuery(e.target.value)} aria-label="Search the national list" />
                  <button className="btn ghost">Search</button>
                  {!opened && (
                    <button type="button" className="btn ghost" disabled={busy} onClick={() => next(item)}>
                      Skip for now
                    </button>
                  )}
                </form>
                {found && (
                  <>
                    <h3>Brands</h3>
                    {found.brands.length ? <ul className="ref-choices">{found.brands.map(brandRow)}</ul> : <p className="muted small">No brand starts with that.</p>}
                    <h3>Generics</h3>
                    <p className="muted small">For a brand the list does not have: link it to what it is.</p>
                    {found.generics.length ? <ul className="ref-choices">{found.generics.map(genericRow)}</ul> : <p className="muted small">No generic has all those words.</p>}
                  </>
                )}
              </>
            )}
          </div>
        )}
        <p className="muted small gap-top">
          Common Drug Codes for India © C-DAC, National Resource Centre for EHR Standards (NRCeS), Ministry of Health and Family Welfare, used under CC BY 4.0. The same codes as the ABDM Drug Registry. A reference for staff: check the pack before relying on it.
        </p>
      </section>
    </>
  )
}
