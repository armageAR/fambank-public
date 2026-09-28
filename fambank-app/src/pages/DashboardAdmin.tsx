import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AxiosError } from 'axios'
import { logout, listUsers, createUser, updateUser, deactivateUser, activateUser, changeUserPassword } from '@/api/auth'
import { getPendingTransactions, confirmTransaction, rejectTransaction, createTransaction, getUserTransactions } from '@/api/transactions'
import { testPushNotification } from '@/api/push'
import { useAuthStore } from '@/store/authStore'
import { useExchangeRate } from '@/hooks/useExchangeRate'
import { usePushNotifications } from '@/hooks/usePushNotifications'
import type { Transaction, User } from '@/types/api'

function fmt(n: number, decimals = 2) {
  return n.toLocaleString('es-AR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
}

// ─── Modal wrapper ────────────────────────────────────────────────────────────
function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-4">
      <div className="w-full max-w-sm bg-white border border-gray-100 shadow-xl rounded-2xl p-6 flex flex-col gap-4">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-gray-900">{title}</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-lg leading-none">✕</button>
        </div>
        {children}
      </div>
    </div>
  )
}

const inputClass = 'w-full rounded-xl bg-gray-50 border border-gray-200 px-4 py-3 text-base text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-colors'
const btnPrimary = 'flex-1 rounded-xl bg-emerald-600 text-white font-semibold py-3 text-sm hover:bg-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed transition-colors'
const btnGhost   = 'flex-1 rounded-xl border border-gray-200 text-gray-600 hover:text-gray-900 hover:border-gray-300 py-3 text-sm transition-colors'

// ─── Create member modal ──────────────────────────────────────────────────────
function CreateMemberModal({ onClose, onCreated }: { onClose: () => void; onCreated: (u: User) => void }) {
  const [form, setForm]         = useState({ name: '', username: '', email: '', password: '' })
  const [error, setError]       = useState<string | null>(null)
  const [fieldErrors, setFE]    = useState<Record<string, string[]>>({})
  const [loading, setLoading]   = useState(false)
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm(f => ({ ...f, [k]: e.target.value }))

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault(); setError(null); setFE({})
    setLoading(true)
    try {
      const { data } = await createUser({ ...form, role: 'member' })
      onCreated(data.data)
    } catch (err) {
      const e = err as AxiosError<{ message: string; errors?: Record<string, string[]> }>
      if (e.response?.status === 422) setFE(e.response.data?.errors ?? {})
      else setError(e.response?.data?.message ?? 'Error al crear.')
    } finally { setLoading(false) }
  }

  return (
    <Modal title="Nuevo miembro" onClose={onClose}>
      <form onSubmit={handleSubmit} className="flex flex-col gap-3" noValidate>
        <div>
          <input value={form.name} onChange={set('name')} placeholder="Nombre" className={inputClass} disabled={loading} />
          {fieldErrors.name && <p className="text-xs text-red-500 mt-1">{fieldErrors.name[0]}</p>}
        </div>
        <div>
          <input autoCapitalize="none" autoCorrect="off" spellCheck={false} value={form.username} onChange={set('username')} placeholder="Usuario (para login)" className={inputClass} disabled={loading} />
          {fieldErrors.username && <p className="text-xs text-red-500 mt-1">{fieldErrors.username[0]}</p>}
        </div>
        <div>
          <input type="email" inputMode="email" autoCapitalize="none" value={form.email} onChange={set('email')} placeholder="Email" className={inputClass} disabled={loading} />
          {fieldErrors.email && <p className="text-xs text-red-500 mt-1">{fieldErrors.email[0]}</p>}
        </div>
        <div>
          <input type="password" value={form.password} onChange={set('password')} placeholder="Contraseña inicial" className={inputClass} disabled={loading} />
          {fieldErrors.password && <p className="text-xs text-red-500 mt-1">{fieldErrors.password[0]}</p>}
        </div>
        {error && <p className="text-xs text-red-500">{error}</p>}
        <div className="flex gap-2">
          <button type="button" onClick={onClose} className={btnGhost}>Cancelar</button>
          <button type="submit" disabled={loading || !form.name || !form.username || !form.email || !form.password} className={btnPrimary}>
            {loading ? 'Creando...' : 'Crear'}
          </button>
        </div>
      </form>
    </Modal>
  )
}


// ─── Edit member modal ────────────────────────────────────────────────────────
function EditMemberModal({ user, onClose, onUpdated }: { user: User; onClose: () => void; onUpdated: (u: User) => void }) {
  const [form, setForm]       = useState({ name: user.name, username: user.username, email: user.email })
  const [error, setError]     = useState<string | null>(null)
  const [fieldErrors, setFE]  = useState<Record<string, string[]>>({})
  const [loading, setLoading] = useState(false)
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm(f => ({ ...f, [k]: e.target.value }))

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault(); setError(null); setFE({})
    setLoading(true)
    try {
      const { data } = await updateUser(user.id, form)
      onUpdated(data.data)
    } catch (err) {
      const e = err as AxiosError<{ message: string; errors?: Record<string, string[]> }>
      if (e.response?.status === 422 && e.response.data?.errors) setFE(e.response.data.errors)
      else setError(e.response?.data?.message ?? 'Error al guardar.')
    } finally { setLoading(false) }
  }

  return (
    <Modal title={`Editar · ${user.name}`} onClose={onClose}>
      <form onSubmit={handleSubmit} className="flex flex-col gap-3" noValidate>
        <div>
          <p className="text-xs text-gray-500 mb-1.5">Nombre</p>
          <input value={form.name} onChange={set('name')} className={inputClass} disabled={loading} />
          {fieldErrors.name && <p className="text-xs text-red-500 mt-1">{fieldErrors.name[0]}</p>}
        </div>
        <div>
          <p className="text-xs text-gray-500 mb-1.5">Usuario (para login)</p>
          <input autoCapitalize="none" autoCorrect="off" spellCheck={false} value={form.username} onChange={set('username')} className={inputClass} disabled={loading} />
          {fieldErrors.username && <p className="text-xs text-red-500 mt-1">{fieldErrors.username[0]}</p>}
        </div>
        <div>
          <p className="text-xs text-gray-500 mb-1.5">Email</p>
          <input type="email" inputMode="email" autoCapitalize="none" value={form.email} onChange={set('email')} className={inputClass} disabled={loading} />
          {fieldErrors.email && <p className="text-xs text-red-500 mt-1">{fieldErrors.email[0]}</p>}
        </div>
        {error && <p className="text-xs text-red-500">{error}</p>}
        <div className="flex gap-2">
          <button type="button" onClick={onClose} className={btnGhost}>Cancelar</button>
          <button type="submit" disabled={loading || !form.name || !form.username || !form.email} className={btnPrimary}>
            {loading ? 'Guardando...' : 'Guardar'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// ─── Change password modal ────────────────────────────────────────────────────
function PasswordModal({ user, onClose, onDone }: { user: User; onClose: () => void; onDone: () => void }) {
  const [password, setPassword] = useState('')
  const [confirm, setConfirm]   = useState('')
  const [error, setError]       = useState<string | null>(null)
  const [loading, setLoading]   = useState(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault(); setError(null); setLoading(true)
    try {
      await changeUserPassword(user.id, password, confirm)
      onDone()
    } catch (err) {
      const e = err as AxiosError<{ message: string; errors?: Record<string, string[]> }>
      setError(e.response?.data?.errors?.password?.[0] ?? e.response?.data?.message ?? 'Error.')
    } finally { setLoading(false) }
  }

  return (
    <Modal title={`Contraseña · ${user.name}`} onClose={onClose}>
      <form onSubmit={handleSubmit} className="flex flex-col gap-3" noValidate>
        <input type="password" value={password} onChange={e => setPassword(e.target.value)} placeholder="Nueva contraseña" className={inputClass} disabled={loading} />
        <input type="password" value={confirm} onChange={e => setConfirm(e.target.value)} placeholder="Confirmar contraseña" className={inputClass} disabled={loading} />
        {error && <p className="text-xs text-red-500">{error}</p>}
        <div className="flex gap-2">
          <button type="button" onClick={onClose} className={btnGhost}>Cancelar</button>
          <button type="submit" disabled={loading || !password || !confirm} className={btnPrimary}>
            {loading ? 'Guardando...' : 'Guardar'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// ─── New transaction modal (admin creates for a member) ───────────────────────
function NewTxModal({
  member, buyRate, sellRate, onClose, onCreated,
}: {
  member: User; buyRate: number; sellRate: number; onClose: () => void; onCreated: () => void
}) {
  const [type, setType]         = useState<'deposit' | 'withdrawal'>('deposit')
  const [amountArs, setArs]     = useState('')
  const [customRate, setCustom] = useState('')
  const [notes, setNotes]       = useState('')
  const [error, setError]       = useState<string | null>(null)
  const [loading, setLoading]   = useState(false)

  const defaultRate = type === 'deposit' ? sellRate : buyRate
  const rate        = parseFloat(customRate) || defaultRate
  const rateLabel   = type === 'deposit' ? 'Cotización de venta' : 'Cotización de compra'
  const ars         = parseFloat(amountArs) || 0
  const usd         = ars > 0 ? Math.round((ars / rate) * 100) / 100 : 0
  const memberBalance = parseFloat(member.balance_usd ?? '0')
  const overBalance   = type === 'withdrawal' && usd > memberBalance && usd > 0

  const handleSubmit = async () => {
    if (ars <= 0 || overBalance) return
    setError(null); setLoading(true)
    try {
      await createTransaction({ type, amount_ars: ars, exchange_rate: rate, user_id: member.id, notes: notes.trim() || undefined })
      onCreated()
    } catch (err) {
      const e = err as AxiosError<{ message: string }>
      setError(e.response?.data?.message ?? 'Error.')
    } finally { setLoading(false) }
  }

  return (
    <Modal title={`Nueva transacción · ${member.name}`} onClose={onClose}>
      {/* Type */}
      <div className="grid grid-cols-2 gap-2">
        <button onClick={() => setType('deposit')} className={`rounded-xl py-3 text-sm font-semibold border transition-colors ${type === 'deposit' ? 'bg-emerald-50 border-emerald-300 text-emerald-600' : 'border-gray-200 text-gray-400 hover:border-gray-300'}`}>
          ↓ Depósito
        </button>
        <button onClick={() => setType('withdrawal')} className={`rounded-xl py-3 text-sm font-semibold border transition-colors ${type === 'withdrawal' ? 'bg-red-50 border-red-300 text-red-600' : 'border-gray-200 text-gray-400 hover:border-gray-300'}`}>
          ↑ Retiro
        </button>
      </div>

      {/* ARS input */}
      <div>
        <p className="text-xs text-gray-500 mb-1.5">Importe en pesos</p>
        <div className="relative">
          <span className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm">$</span>
          <input type="number" inputMode="decimal" step="1" min="1" value={amountArs} onChange={e => setArs(e.target.value)} placeholder="0" className={`${inputClass} pl-8`} autoFocus />
        </div>
      </div>

      {/* Rate (editable by admin) */}
      <div>
        <p className="text-xs text-gray-500 mb-1.5">{rateLabel} <span className="text-gray-400">(editable)</span></p>
        <div className="relative">
          <span className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm">$</span>
          <input type="number" inputMode="decimal" value={customRate || String(Math.round(defaultRate))} onChange={e => setCustom(e.target.value)} className={`${inputClass} pl-8`} />
        </div>
      </div>

      {/* USD preview */}
      <div className={`rounded-xl px-4 py-3 flex items-center justify-between ${type === 'deposit' ? 'bg-emerald-50 border border-emerald-100' : 'bg-red-50 border border-red-100'}`}>
        <p className="text-xs text-gray-500">USD {type === 'deposit' ? 'a acreditar' : 'a retirar'}</p>
        <p className={`text-lg font-bold ${type === 'deposit' ? 'text-emerald-600' : 'text-red-600'}`}>
          {ars > 0 ? `USD ${fmt(usd)}` : '—'}
        </p>
      </div>

      {/* Notes */}
      <div>
        <p className="text-xs text-gray-500 mb-1.5">Comentario <span className="text-gray-400">(opcional)</span></p>
        <textarea
          value={notes}
          onChange={e => setNotes(e.target.value)}
          placeholder="Observaciones de la operación..."
          rows={2}
          className={`${inputClass} resize-none`}
        />
      </div>

      {overBalance && (
        <p className="text-xs text-red-500">Saldo insuficiente. Tiene USD {fmt(memberBalance)}.</p>
      )}
      {error && <p className="text-xs text-red-500">{error}</p>}

      <div className="flex gap-2">
        <button onClick={onClose} className={btnGhost}>Cancelar</button>
        <button onClick={handleSubmit} disabled={ars <= 0 || overBalance || loading} className={`${btnPrimary} ${type === 'deposit' ? 'bg-emerald-600 text-white hover:bg-emerald-500' : 'bg-red-500 text-white hover:bg-red-400'}`}>
          {loading ? 'Creando...' : '✓ Confirmar'}
        </button>
      </div>
    </Modal>
  )
}

// ─── Transaction history modal ────────────────────────────────────────────────
function HistoryModal({ user, onClose }: { user: User; onClose: () => void }) {
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loading, setLoading]           = useState(true)
  const [page, setPage]                 = useState(1)
  const [lastPage, setLastPage]         = useState(1)
  const [total, setTotal]               = useState(0)

  const load = useCallback(async (p: number) => {
    setLoading(true)
    try {
      const { data } = await getUserTransactions(user.id, p)
      setTransactions(data.data)
      setLastPage(data.meta.last_page)
      setTotal(data.meta.total)
    } catch { /* ignore */ }
    finally { setLoading(false) }
  }, [user.id])

  useEffect(() => { load(1) }, [load])

  const go = (p: number) => { setPage(p); load(p) }

  return (
    <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-4">
      <div className="w-full max-w-sm bg-white border border-gray-100 shadow-xl rounded-2xl flex flex-col max-h-[85dvh]">

        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100 shrink-0">
          <div>
            <p className="text-sm font-semibold text-gray-900">{user.name}</p>
            <p className="text-xs text-gray-400">{total} movimiento{total !== 1 ? 's' : ''}</p>
          </div>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-lg leading-none">✕</button>
        </div>

        {/* List */}
        <div className="flex-1 overflow-y-auto p-4 flex flex-col gap-2">
          {loading ? (
            <p className="text-xs text-gray-400 text-center py-8">Cargando...</p>
          ) : transactions.length === 0 ? (
            <p className="text-xs text-gray-400 text-center py-8">Sin movimientos.</p>
          ) : (
            transactions.map(tx => {
              const isDeposit  = tx.type === 'deposit'
              const isPending  = tx.status === 'pending'
              const usd = parseFloat(tx.amount_usd)
              const ars = parseFloat(tx.amount_ars)
              const date = new Date(tx.created_at).toLocaleDateString('es-AR', {
                day: '2-digit', month: '2-digit', year: '2-digit',
              })

              return (
                <div key={tx.id} className={`rounded-xl border px-4 py-3 flex flex-col gap-1 ${
                  isPending ? 'bg-amber-50/50 border-amber-200' : 'bg-gray-50 border-gray-100'
                }`}>
                  <div className="flex items-center gap-2">
                    <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs shrink-0 ${
                      isDeposit ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-500'
                    }`}>
                      {isDeposit ? '↓' : '↑'}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-1.5 flex-wrap">
                        <span className={`text-sm font-semibold ${isDeposit ? 'text-emerald-600' : 'text-red-500'}`}>
                          {isDeposit ? '+' : '-'} USD {fmt(usd)}
                        </span>
                        {isPending && (
                          <span className="text-xs bg-amber-100 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-md">pendiente</span>
                        )}
                        {tx.status === 'rejected' && (
                          <span className="text-xs bg-red-50 text-red-500 border border-red-200 px-1.5 py-0.5 rounded-md">rechazada</span>
                        )}
                        {tx.status === 'cancelled' && (
                          <span className="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-md">cancelada</span>
                        )}
                      </div>
                      <p className="text-xs text-gray-400 truncate">
                        $ {fmt(ars, 0)} · {date}
                        {tx.created_by_name && (
                          <span className="text-amber-600/80"> · por {tx.created_by_name}</span>
                        )}
                      </p>
                    </div>
                  </div>
                  {tx.notes && (
                    <p className="text-xs text-gray-400 pl-9 italic">"{tx.notes}"</p>
                  )}
                </div>
              )
            })
          )}
        </div>

        {/* Pagination */}
        {lastPage > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-gray-100 shrink-0">
            <button
              onClick={() => go(page - 1)}
              disabled={page <= 1}
              className="text-xs border border-gray-200 text-gray-500 hover:text-gray-900 hover:border-gray-300 px-3 py-2 rounded-lg transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
            >
              ← Anterior
            </button>
            <p className="text-xs text-gray-400">{page} / {lastPage}</p>
            <button
              onClick={() => go(page + 1)}
              disabled={page >= lastPage}
              className="text-xs border border-gray-200 text-gray-500 hover:text-gray-900 hover:border-gray-300 px-3 py-2 rounded-lg transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
            >
              Siguiente →
            </button>
          </div>
        )}

      </div>
    </div>
  )
}

// ─── Pending transaction card ─────────────────────────────────────────────────
function PendingTxCard({
  tx, onConfirm, onReject,
}: {
  tx: Transaction
  onConfirm: (tx: Transaction, newTc?: number) => void
  onReject: (tx: Transaction) => void
}) {
  const [editing, setEditing]   = useState(false)
  const [tc, setTc]             = useState(tx.exchange_rate)
  const isDeposit = tx.type === 'deposit'
  const ars = parseFloat(tx.amount_ars)                          // fijo: lo que el usuario ingresó
  const tcNum = parseFloat(tc)
  const usd = tcNum > 0 ? ars / tcNum : null                     // recalculado si admin cambia TC; null si TC inválido

  const date = new Date(tx.created_at).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit' })

  return (
    <div className="rounded-2xl bg-white border border-amber-200 shadow-sm px-4 py-4 flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        <div>
          <div className="flex items-center gap-1.5">
            <span className={`text-sm font-semibold ${isDeposit ? 'text-emerald-600' : 'text-red-500'}`}>
              {isDeposit ? '↓ Depósito' : '↑ Retiro'} · {tx.user?.name}
            </span>
          </div>
          <p className="text-xs text-gray-400">{tx.user?.email} · {date}</p>
        </div>
        <span className="text-xs bg-amber-100 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-md shrink-0">pendiente</span>
      </div>

      <div className="flex items-center justify-between border-t border-gray-100 pt-2">
        <span className="text-xs text-gray-500">Pesos <span className="text-gray-300">(fijo)</span></span>
        <span className="text-base font-bold text-gray-900">$ {fmt(ars, 0)}</span>
      </div>

      {/* TC editable */}
      <div className="flex items-center justify-between">
        <span className="text-xs text-gray-500">Tipo de cambio</span>
        {editing ? (
          <input
            type="number"
            value={tc}
            onChange={e => setTc(e.target.value)}
            className="w-28 rounded-lg bg-gray-50 border border-gray-200 px-3 py-1 text-sm text-gray-900 text-right focus:outline-none focus:border-emerald-500"
            autoFocus
            onBlur={() => setEditing(false)}
          />
        ) : (
          <button onClick={() => setEditing(true)} className="text-sm text-gray-900 hover:text-emerald-600 transition-colors">
            {tcNum > 0 ? `$ ${fmt(tcNum, 0)}` : '—'} <span className="text-xs text-gray-400">(editar)</span>
          </button>
        )}
      </div>

      <div className="flex items-center justify-between">
        <span className="text-xs text-gray-500">USD <span className="text-gray-300">(recalculado)</span></span>
        <span className="text-sm font-semibold text-gray-900">{usd !== null ? `USD ${fmt(usd)}` : '—'}</span>
      </div>

      {usd === null && (
        <p className="text-xs text-red-500">Ingresá un tipo de cambio válido para confirmar.</p>
      )}

      <div className="flex gap-2 pt-1">
        <button
          onClick={() => onReject(tx)}
          className="flex-1 text-xs border border-red-200 text-red-500 hover:border-red-300 rounded-xl py-2.5 transition-colors"
        >
          Rechazar
        </button>
        <button
          onClick={() => onConfirm(tx, tcNum !== parseFloat(tx.exchange_rate) ? tcNum : undefined)}
          disabled={usd === null}
          className="flex-1 text-xs bg-emerald-50 border border-emerald-200 text-emerald-600 hover:bg-emerald-100 rounded-xl py-2.5 font-semibold transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
        >
          Confirmar
        </button>
      </div>
    </div>
  )
}

// ─── Member card ──────────────────────────────────────────────────────────────
function MemberCard({
  member, rate, onDeactivate, onActivate, onChangePassword, onNewTx, onViewHistory, onEdit,
}: {
  member: User; rate: number | null
  onDeactivate: (u: User) => void; onActivate: (u: User) => void
  onChangePassword: (u: User) => void; onNewTx: (u: User) => void
  onViewHistory: (u: User) => void; onEdit: (u: User) => void
}) {
  const [menuOpen, setMenuOpen] = useState(false)
  const usd = parseFloat(member.balance_usd ?? '0')
  const ars = rate ? usd * rate : null
  const lastLogin = member.last_login_at
    ? new Date(member.last_login_at).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
    : 'Nunca'

  return (
    <div className={`rounded-2xl border shadow-sm px-4 py-4 flex flex-col gap-3 ${member.active ? 'bg-white border-gray-100' : 'bg-gray-50 border-gray-100'}`}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className={`text-sm font-semibold ${member.active ? 'text-gray-900' : 'text-gray-400'}`}>
            {member.name} <span className="text-xs font-normal text-gray-400">@{member.username}</span>
          </p>
          <p className="text-xs text-gray-400 truncate">{member.email}</p>
        </div>
        {!member.active && (
          <span className="text-xs bg-gray-100 text-gray-500 border border-gray-200 px-2 py-0.5 rounded-md shrink-0">inactivo</span>
        )}
      </div>

      {member.active && (
        <div className="flex items-end justify-between border-t border-gray-100 pt-3">
          <div>
            <p className="text-xs text-gray-400">Último ingreso</p>
            <p className="text-xs text-gray-600 mt-0.5">{lastLogin}</p>
          </div>
          <div className="text-right">
            <p className="text-base font-bold text-emerald-600">USD {fmt(usd)}</p>
            {ars !== null && <p className="text-xs text-gray-400">$ {fmt(ars, 0)}</p>}
          </div>
        </div>
      )}

      <div className="flex gap-2 relative">
        {member.active ? (
          <button onClick={() => onNewTx(member)} className="flex-1 text-xs bg-emerald-50 border border-emerald-200 text-emerald-600 hover:bg-emerald-100 rounded-lg py-2 font-semibold transition-colors">
            + Transacción
          </button>
        ) : (
          <button onClick={() => onActivate(member)} className="flex-1 text-xs border border-emerald-200 text-emerald-600 hover:border-emerald-300 rounded-lg py-2 font-semibold transition-colors">
            Activar
          </button>
        )}
        <button onClick={() => setMenuOpen(v => !v)} className="px-3 text-sm border border-gray-200 text-gray-500 hover:text-gray-900 hover:border-gray-300 rounded-lg transition-colors">
          ⋮
        </button>

        {menuOpen && (
          <>
            <div className="fixed inset-0 z-10" onClick={() => setMenuOpen(false)} />
            <div className="absolute right-0 bottom-full mb-2 z-20 w-44 bg-white border border-gray-100 shadow-lg rounded-xl overflow-hidden flex flex-col">
              <button onClick={() => { setMenuOpen(false); onViewHistory(member) }} className="text-left px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50 transition-colors">
                Movimientos
              </button>
              {member.active && (
                <button onClick={() => { setMenuOpen(false); onEdit(member) }} className="text-left px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50 transition-colors">
                  Editar
                </button>
              )}
              {member.active && (
                <button onClick={() => { setMenuOpen(false); onChangePassword(member) }} className="text-left px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50 transition-colors">
                  Contraseña
                </button>
              )}
              {member.active && (
                <button onClick={() => { setMenuOpen(false); onDeactivate(member) }} className="text-left px-4 py-2.5 text-sm text-red-500 hover:bg-red-50 transition-colors">
                  Desactivar
                </button>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

// ─── Dashboard Admin ──────────────────────────────────────────────────────────
export default function DashboardAdmin() {
  const navigate = useNavigate()
  const { user, clearAuth } = useAuthStore()
  const { rate, loading: rateLoading, error: rateError, refresh } = useExchangeRate()
  const { status: pushStatus, subscribe: subscribePush } = usePushNotifications()

  const [members, setMembers]         = useState<User[]>([])
  const [pending, setPending]         = useState<Transaction[]>([])
  const [loadingUsers, setLoadingU]   = useState(true)
  const [showInactive, setShowInact]  = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [pushInfo, setPushInfo]       = useState<string | null>(null)
  const [testingPush, setTestingPush] = useState(false)

  const [tab, setTab] = useState<'dashboard' | 'members'>('dashboard')

  const [modal, setModal] = useState<
    | { type: 'create' }
    | { type: 'edit'; user: User }
    | { type: 'password'; user: User }
    | { type: 'newTx'; user: User }
    | { type: 'history'; user: User }
    | null
  >(null)

  const loadData = useCallback(async () => {
    setLoadingU(true)
    try {
      const [usersRes, txRes] = await Promise.all([listUsers(), getPendingTransactions()])
      setMembers(usersRes.data.data.filter(u => u.role === 'member'))
      setPending(txRes.data.data)
    } catch { /* ignore */ }
    finally { setLoadingU(false) }
  }, [])

  useEffect(() => { loadData() }, [loadData])

  const handleLogout = async () => {
    try { await logout() } catch { /* ignore */ }
    clearAuth(); navigate('/login', { replace: true })
  }

  const errorMessage = (err: unknown, fallback: string) => {
    const e = err as AxiosError<{ message?: string }>
    return e.response?.data?.message ?? fallback
  }

  // Prueba end-to-end: servidor → push service → este dispositivo.
  // Si la entrega falla (ej. suscripción vieja con otra clave VAPID),
  // re-suscribe automáticamente y reintenta una vez.
  const handleTestPush = async () => {
    setActionError(null); setPushInfo(null); setTestingPush(true)
    try {
      const { data } = await testPushNotification()
      setPushInfo(data.message)
    } catch {
      try {
        await subscribePush()
        const { data } = await testPushNotification()
        setPushInfo(`${data.message} (la suscripción fue renovada)`)
      } catch (err2) {
        setActionError(errorMessage(err2, 'No se pudo enviar la notificación de prueba.'))
      }
    } finally {
      setTestingPush(false)
    }
  }

  const handleDeactivate = async (u: User) => {
    if (!confirm(`¿Desactivar a ${u.name}?`)) return
    setActionError(null)
    try {
      await deactivateUser(u.id)
      setMembers(ms => ms.map(m => m.id === u.id ? { ...m, active: false } : m))
    } catch (err) {
      setActionError(errorMessage(err, `No se pudo desactivar a ${u.name}.`))
    }
  }

  const handleActivate = async (u: User) => {
    setActionError(null)
    try {
      await activateUser(u.id)
      setMembers(ms => ms.map(m => m.id === u.id ? { ...m, active: true } : m))
    } catch (err) {
      setActionError(errorMessage(err, `No se pudo activar a ${u.name}.`))
    }
  }

  const handleConfirm = async (tx: Transaction, newTc?: number) => {
    setActionError(null)
    try {
      await confirmTransaction(tx.id, newTc)
    } catch (err) {
      setActionError(errorMessage(err, 'No se pudo confirmar la transacción.'))
    }
    // Recargar siempre: si falló porque ya no estaba pendiente, saca la card vieja
    await loadData()
  }

  const handleReject = async (tx: Transaction) => {
    if (!confirm(`¿Rechazar esta transacción de ${tx.user?.name}?`)) return
    setActionError(null)
    try {
      await rejectTransaction(tx.id)
      setPending(ps => ps.filter(p => p.id !== tx.id))
    } catch (err) {
      setActionError(errorMessage(err, 'No se pudo rechazar la transacción.'))
      await loadData()
    }
  }

  const activeMembers   = members.filter(m => m.active)
  const inactiveMembers = members.filter(m => !m.active)
  const visibleMembers  = showInactive ? members : activeMembers
  const totalUsd        = activeMembers.reduce((s, m) => s + parseFloat(m.balance_usd ?? '0'), 0)
  const totalArs        = rate ? totalUsd * rate.blue.buy : null

  return (
    <div className="min-h-[100dvh] bg-gray-50 text-gray-900 flex flex-col">

      <header className="flex items-center justify-between px-5 py-4 bg-white border-b border-gray-100">
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white text-base font-extrabold shrink-0">
            $
          </div>
          <div>
            <p className="text-xs text-gray-400 tracking-[0.2em] uppercase">FamBank</p>
            <div className="flex items-center gap-2 mt-0.5">
              <p className="text-sm font-semibold text-gray-900">{user?.name}</p>
              <span className="text-xs bg-amber-100 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-md">Admin</span>
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {pushStatus === 'loading' && (
            <span className="text-xs text-gray-300 px-2">...</span>
          )}
          {pushStatus === 'unsupported' && (
            <span className="text-xs text-gray-300 px-2">sin push</span>
          )}
          {pushStatus === 'denied' && (
            <span className="text-xs text-red-500 px-2">notif. bloqueadas</span>
          )}
          {pushStatus === 'unsubscribed' && (
            <button
              onClick={subscribePush}
              className="text-xs border border-gray-200 text-gray-500 hover:border-amber-300 hover:text-amber-600 px-2 py-1.5 rounded-lg transition-colors"
            >
              activar notif.
            </button>
          )}
          {pushStatus === 'subscribed' && (
            <button
              onClick={handleTestPush}
              disabled={testingPush}
              title="Notificaciones activas · click para enviar una prueba"
              className="text-xs border border-emerald-200 text-emerald-600 px-2 py-1.5 rounded-lg disabled:opacity-50"
            >
              {testingPush ? 'probando...' : 'notif. activas'}
            </button>
          )}
          <button onClick={handleLogout} className="text-xs text-gray-400 hover:text-gray-700 border border-gray-200 hover:border-gray-300 px-3 py-1.5 rounded-lg transition-colors">
            Salir
          </button>
        </div>
      </header>

      <main className="flex-1 flex flex-col gap-4 p-5 pb-8">

        {/* Action error banner */}
        {actionError && (
          <div className="rounded-xl bg-red-50 border border-red-200 px-4 py-3 flex items-center justify-between gap-3">
            <p className="text-xs text-red-600">{actionError}</p>
            <button onClick={() => setActionError(null)} className="text-red-500 hover:text-red-600 text-sm leading-none shrink-0">✕</button>
          </div>
        )}

        {/* Push info banner */}
        {pushInfo && (
          <div className="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 flex items-center justify-between gap-3">
            <p className="text-xs text-emerald-700">{pushInfo}</p>
            <button onClick={() => setPushInfo(null)} className="text-emerald-600 hover:text-emerald-700 text-sm leading-none shrink-0">✕</button>
          </div>
        )}

        {/* Pending transactions */}
        {pending.length > 0 && (
          <div className="flex flex-col gap-3">
            <div className="flex items-center gap-2">
              <p className="text-xs text-gray-400 tracking-wide uppercase">Pendientes de aprobación</p>
              <span className="text-xs bg-amber-100 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-md">
                {pending.length}
              </span>
            </div>
            {pending.map(tx => (
              <PendingTxCard
                key={tx.id}
                tx={tx}
                onConfirm={handleConfirm}
                onReject={handleReject}
              />
            ))}
          </div>
        )}

        {/* Tabs */}
        <div className="flex gap-1 bg-white border border-gray-100 rounded-xl p-1 shadow-sm">
          <button
            onClick={() => setTab('dashboard')}
            className={`flex-1 text-sm font-semibold py-2 rounded-lg transition-colors ${tab === 'dashboard' ? 'bg-emerald-600 text-white' : 'text-gray-500 hover:text-gray-900'}`}
          >
            Dashboard
          </button>
          <button
            onClick={() => setTab('members')}
            className={`flex-1 text-sm font-semibold py-2 rounded-lg transition-colors ${tab === 'members' ? 'bg-emerald-600 text-white' : 'text-gray-500 hover:text-gray-900'}`}
          >
            Miembros
          </button>
        </div>

        {tab === 'dashboard' ? (
          <div className="flex flex-col gap-4">

            {/* Bank balance */}
            <div className="rounded-2xl bg-white border border-gray-100 shadow-sm px-5 py-5">
              <p className="text-xs text-gray-400 tracking-wide uppercase mb-3">Saldo total del banco</p>
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl bg-emerald-50 px-4 py-3">
                  <p className="text-xs text-emerald-700 mb-1">Dólares</p>
                  <p className="text-xl font-bold text-emerald-700">USD {fmt(totalUsd)}</p>
                </div>
                <div className="rounded-xl bg-gray-50 px-4 py-3">
                  <p className="text-xs text-gray-500 mb-1">Pesos <span className="text-gray-400">(blue compra)</span></p>
                  <p className="text-xl font-bold text-gray-900">{totalArs !== null ? `$ ${fmt(totalArs, 0)}` : '—'}</p>
                </div>
              </div>
            </div>

            {/* Exchange rates */}
            <div className="rounded-2xl bg-white border border-gray-100 shadow-sm px-5 py-5">
              <div className="flex items-center justify-between mb-3">
                <p className="text-xs text-gray-400 tracking-wide uppercase">Cotizaciones</p>
                {!rateLoading && <button onClick={refresh} className="text-xs text-gray-400 hover:text-emerald-600 transition-colors">actualizar</button>}
              </div>
              {rateLoading && !rate ? (
                <p className="text-xs text-gray-400">Obteniendo cotización...</p>
              ) : rateError && !rate ? (
                <p className="text-xs text-red-500">No se pudo obtener la cotización.</p>
              ) : rate ? (
                <div className="flex flex-col gap-4">
                  <div>
                    <p className="text-xs text-gray-500 mb-2">Dólar blue</p>
                    <div className="grid grid-cols-2 gap-3">
                      <div className="rounded-xl bg-gray-50 px-4 py-3">
                        <p className="text-xs text-gray-500 text-right">Compra</p>
                        <p className="text-xl font-bold text-gray-900 text-right">$ {fmt(rate.blue.buy, 0)}</p>
                      </div>
                      <div className="rounded-xl bg-gray-50 px-4 py-3">
                        <p className="text-xs text-gray-500 text-right">Venta</p>
                        <p className="text-xl font-bold text-gray-900 text-right">$ {fmt(rate.blue.sell, 0)}</p>
                      </div>
                    </div>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500 mb-2">Dólar oficial</p>
                    <div className="grid grid-cols-2 gap-3">
                      <div className="rounded-xl bg-gray-50 px-4 py-3">
                        <p className="text-xs text-gray-500 text-right">Compra</p>
                        <p className="text-xl font-bold text-gray-900 text-right">$ {fmt(rate.oficial.buy, 0)}</p>
                      </div>
                      <div className="rounded-xl bg-gray-50 px-4 py-3">
                        <p className="text-xs text-gray-500 text-right">Venta</p>
                        <p className="text-xl font-bold text-gray-900 text-right">$ {fmt(rate.oficial.sell, 0)}</p>
                      </div>
                    </div>
                  </div>
                  <p className="text-xs text-gray-300 text-right">Fuente: Bluelytics · actualiza cada 5min</p>
                </div>
              ) : null}
            </div>

          </div>
        ) : (
          <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-xs text-gray-400 tracking-wide uppercase">Miembros</p>
                <p className="text-xs text-gray-400 mt-0.5">Total: <span className="text-gray-600">USD {fmt(totalUsd)}</span></p>
              </div>
              <button onClick={() => setModal({ type: 'create' })} className="text-xs bg-emerald-600 text-white font-semibold px-3 py-2 rounded-xl hover:bg-emerald-500 active:bg-emerald-700 transition-colors">
                + Nuevo miembro
              </button>
            </div>

            {inactiveMembers.length > 0 && (
              <button onClick={() => setShowInact(v => !v)} className="flex items-center gap-2 text-xs text-gray-500 hover:text-gray-900 transition-colors self-start">
                <span className={`w-8 h-4 rounded-full flex items-center px-0.5 transition-colors ${showInactive ? 'bg-emerald-500' : 'bg-gray-200'}`}>
                  <span className={`w-3 h-3 rounded-full bg-white transition-transform ${showInactive ? 'translate-x-4' : 'translate-x-0'}`} />
                </span>
                Ver inactivos ({inactiveMembers.length})
              </button>
            )}

            {loadingUsers ? (
              <p className="text-xs text-gray-400">Cargando...</p>
            ) : visibleMembers.length === 0 ? (
              <p className="text-xs text-gray-400">No hay miembros.</p>
            ) : (
              visibleMembers.map(m => (
                <MemberCard
                  key={m.id}
                  member={m}
                  rate={rate?.blue.buy ?? null}
                  onDeactivate={handleDeactivate}
                  onActivate={handleActivate}
                  onEdit={u => setModal({ type: 'edit', user: u })}
                  onChangePassword={u => setModal({ type: 'password', user: u })}
                  onNewTx={u => setModal({ type: 'newTx', user: u })}
                  onViewHistory={u => setModal({ type: 'history', user: u })}
                />
              ))
            )}
          </div>
        )}

      </main>

      {/* Modals */}
      {modal?.type === 'create' && (
        <CreateMemberModal
          onClose={() => setModal(null)}
          onCreated={u => { setMembers(ms => [...ms, u]); setModal(null) }}
        />
      )}
      {modal?.type === 'edit' && (
        <EditMemberModal
          user={modal.user}
          onClose={() => setModal(null)}
          onUpdated={u => { setMembers(ms => ms.map(m => m.id === u.id ? u : m)); setModal(null) }}
        />
      )}
      {modal?.type === 'password' && (
        <PasswordModal user={modal.user} onClose={() => setModal(null)} onDone={() => setModal(null)} />
      )}
      {modal?.type === 'history' && (
        <HistoryModal user={modal.user} onClose={() => setModal(null)} />
      )}
      {modal?.type === 'newTx' && rate && (
        <NewTxModal
          member={modal.user}
          buyRate={rate.blue.buy}
          sellRate={rate.blue.sell}
          onClose={() => setModal(null)}
          onCreated={() => { setModal(null); loadData() }}
        />
      )}
    </div>
  )
}
