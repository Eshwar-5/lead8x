import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  timeout: 60000,
  headers: { 'Content-Type': 'application/json' },
})

api.interceptors.request.use(config => {
  const token = localStorage.getItem('lead8x_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
}, Promise.reject)

api.interceptors.response.use(
  res => res,
  err => {
    if (err.response?.status === 401) {
      localStorage.removeItem('lead8x_token')
      localStorage.removeItem('lead8x_user')
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)

// Auth
export const login = (email, password) =>
  api.post('/auth/login.php', { email, password })

// Leads
export const getLeads = (params) =>
  api.get('/leads/list.php', { params })

export const getDevices = () =>
  api.get('/filters/devices.php')

// Download: pass export_ids as comma string for selection-wise
export const downloadLeads = (params) =>
  api.get('/leads/download.php', { params, responseType: 'blob' })

// Upload Step 1: parse + preview
export const uploadLeadsPreview = (formData) =>
  api.post('/leads/upload.php', formData, { headers: { 'Content-Type': 'multipart/form-data' } })

// Upload Step 2: confirm save
export const confirmUpload = (data) =>
  api.post('/leads/upload-confirm.php', data)

// Feedback Sync upload
export const uploadFeedback = (formData) =>
  api.post('/leads/upload-feedback.php', formData, { headers: { 'Content-Type': 'multipart/form-data' } })

export const updateFeedback = (data) =>
  api.post('/leads/feedback.php', data)

export const bulkFeedback = (formData) =>
  api.post('/leads/feedback.php', formData, { headers: { 'Content-Type': 'multipart/form-data' } })

export const getTimeline = (lead_id) =>
  api.get('/leads/timeline.php', { params: { lead_id } })

export const deleteLeads = (data) =>
  api.post('/leads/delete.php', data)

export const mergeLeads = (ids) =>
  api.post('/leads/merge.php', { ids })

// Projects
// mode: 'active_leads' (default) | 'master' | 'by_location'
// For by_location, pass params: { mode: 'by_location', location: 'Bangalore East' }
export const getProjects = (params) =>
  api.get('/projects/list.php', { params })

export const saveProject = (data) =>
  api.post('/projects/save.php', data)

// Project Locations
// Get single project's location: getLocations({ project_name: 'X' })
// Get ALL distinct location names: getLocations({ all_locations: 1 })
export const getLocations = (paramsOrProjectName) => {
  // Backwards-compatible: old callers passed a string (project_name)
  if (typeof paramsOrProjectName === 'string') {
    return api.get('/projects/locations.php', { params: { project_name: paramsOrProjectName } })
  }
  return api.get('/projects/locations.php', { params: paramsOrProjectName })
}

// Convenience: load all distinct location names for the Location filter dropdown
export const getAllLocations = () =>
  api.get('/projects/locations.php', { params: { all_locations: 1 } })

export const saveLocation = (data) =>
  api.post('/projects/locations.php', data)

export const deleteLocation = (id) =>
  api.delete('/projects/locations.php', { params: { id } })

// Bulk URL update for a project's leads
export const bulkUpdateUrl = (data) =>
  api.post('/projects/bulk-url.php', data)

// Users
export const getUsers    = ()     => api.get('/users/list.php')
export const createUser  = (data) => api.post('/users/create.php', data)
export const updateUser  = (data) => api.put('/users/update.php', data)
export const deleteUser  = (id)   => api.delete('/users/delete.php', { data: { id } })

// Distribution
export const distribute = (data) =>
  api.post('/distribution/distribute.php', data)

// Admin — pass optional params e.g. { location: 'Bangalore East' } to scope dashboard
export const getStats       = (params) => api.get('/admin/stats.php', { params })
export const getActivityLog = (p)      => api.get('/admin/activity-log.php', { params: p })
export const downloadBackup = ()       => api.post('/admin/backup.php', {}, { responseType: 'blob' })

// Webhooks
export const getWebhookSources   = ()     => api.get('/webhooks-admin/settings.php')
export const saveWebhookSource   = (data) => api.post('/webhooks-admin/settings.php', data)
export const deleteWebhookSource = (id)   => api.delete('/webhooks-admin/settings.php', { params: { id } })
export const getWebhookLogs      = (p)    => api.get('/webhooks-admin/logs.php', { params: p })

// Helper
export const triggerDownload = (blob, filename) => {
  const url = window.URL.createObjectURL(blob)
  const a   = document.createElement('a')
  a.href = url; a.download = filename; a.click()
  window.URL.revokeObjectURL(url)
}

export default api
