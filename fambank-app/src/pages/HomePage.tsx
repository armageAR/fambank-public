export default function HomePage() {
  return (
    <div className="min-h-[100dvh] bg-gradient-to-b from-emerald-50 to-white flex flex-col items-center justify-center">
      <div className="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white text-3xl font-extrabold shadow-lg shadow-emerald-600/20 mb-4">
        $
      </div>
      <div className="text-xs tracking-[0.3em] text-gray-400 uppercase mb-1">Bienvenido a</div>
      <h1 className="text-4xl font-bold tracking-tight text-gray-900">FamBank</h1>
    </div>
  )
}
