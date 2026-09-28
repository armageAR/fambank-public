import api from './client'
import type { User } from '@/types/api'

export const login = (username: string, password: string, deviceName: string) =>
  api.post<{ data: { token: string; user: User }; message: string }>('/api/auth/login', {
    username,
    password,
    device_name: deviceName,
  })

export const updateProfile = (payload: {
  email?: string
  password?: string
  password_confirmation?: string
  current_password?: string
}) => api.put<{ data: User; message: string }>('/api/auth/profile', payload)

export const logout = () => api.post('/api/auth/logout')

export const me = () => api.get<{ data: User }>('/api/auth/me')

export const forgotPassword = (email: string) =>
  api.post<{ message: string }>('/api/auth/forgot-password', { email })

export const resetPassword = (
  token: string,
  email: string,
  password: string,
  passwordConfirmation: string,
) =>
  api.post<{ message: string }>('/api/auth/reset-password', {
    token,
    email,
    password,
    password_confirmation: passwordConfirmation,
  })

export const listUsers = () => api.get<{ data: User[] }>('/api/admin/users')

export const createUser = (data: { name: string; username: string; email: string; password: string; role: string }) =>
  api.post<{ data: User; message: string }>('/api/admin/users', data)

export const updateUser = (id: number, data: { name?: string; username?: string; email?: string; role?: string }) =>
  api.put<{ data: User; message: string }>(`/api/admin/users/${id}`, data)

export const deactivateUser = (id: number) =>
  api.delete<{ message: string }>(`/api/admin/users/${id}`)

export const activateUser = (id: number) =>
  api.put<{ data: User; message: string }>(`/api/admin/users/${id}/activate`)

export const changeUserPassword = (id: number, password: string, passwordConfirmation: string) =>
  api.put<{ message: string }>(`/api/admin/users/${id}/password`, {
    password,
    password_confirmation: passwordConfirmation,
  })
