import { Navigate } from 'react-router-dom'
import { useAuthStore } from '@/store/authStore'

interface Props {
  children: React.ReactNode
  role?: 'admin' | 'member'
}

export default function ProtectedRoute({ children, role }: Props) {
  const { token, user } = useAuthStore()

  if (!token || !user) return <Navigate to="/login" replace />

  if (role && user.role !== role) {
    return <Navigate to={user.role === 'admin' ? '/dashboard/admin' : '/dashboard/member'} replace />
  }

  return <>{children}</>
}
