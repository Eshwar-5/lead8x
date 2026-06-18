import { useEffect, useState } from 'react'
import { getUsers, createUser, updateUser, deleteUser } from '../api/axios.js'
import { UserPlus, Edit, UserX, RefreshCw, X } from 'lucide-react'
import toast from 'react-hot-toast'

const ROLES = ['Admin','Caller','Relationship Manager','Manager']

const blankForm = { name:'', email:'', password:'', role:'Caller', is_active: true }

export default function Users() {
  const [users, setUsers]     = useState([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal]     = useState(null) // null | 'create' | 'edit'
  const [form, setForm]       = useState(blankForm)
  const [saving, setSaving]   = useState(false)

  const load = async () => {
    setLoading(true)
    try {
      const res = await getUsers()
      setUsers(res.data.data.users)
    } catch { toast.error('Failed to load users.') }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  const openCreate = () => { setForm(blankForm); setModal('create') }
  const openEdit   = (u) => {
    setForm({ id: u.id, name: u.name, email: u.email, password: '', role: u.role, is_active: u.is_active == 1 })
    setModal('edit')
  }

  const handleSave = async (e) => {
    e.preventDefault()
    setSaving(true)
    try {
      if (modal === 'create') {
        await createUser(form)
        toast.success('User created successfully!')
      } else {
        await updateUser({ ...form, is_active: form.is_active ? 1 : 0 })
        toast.success('User updated successfully!')
      }
      setModal(null); load()
    } catch (err) {
      toast.error(err.response?.data?.message || 'Save failed.')
    } finally { setSaving(false) }
  }

  const handleDelete = async (u) => {
    if (!confirm(`Deactivate "${u.name}"? They will lose access but data is preserved.`)) return
    try {
      await deleteUser(u.id)
      toast.success('User deactivated.')
      load()
    } catch (err) { toast.error(err.response?.data?.message || 'Failed.') }
  }

  const roleGroups = ROLES.reduce((acc, role) => {
    acc[role] = users.filter(u => u.role === role)
    return acc
  }, {})

  return (
    <div>
      <div className="topbar">
        <h1>User Management</h1>
        <div className="topbar-actions">
          <button id="btn-create-user" className="btn btn-primary btn-sm" onClick={openCreate}><UserPlus size={15}/> Add User</button>
          <button className="btn btn-secondary btn-sm" onClick={load}><RefreshCw size={14}/></button>
        </div>
      </div>

      <div className="page">
        {loading
          ? <div className="loading-overlay"><div className="spinner"/><span>Loading users…</span></div>
          : ROLES.map(role => {
              const group = roleGroups[role]
              if (group.length === 0) return null
              return (
                <div key={role} className="mb-6">
                  <div className="section-title">
                    <span className={`role-badge role-${role.replace(' ','')}`}>{role}</span>
                    <span className="text-muted text-sm">({group.length})</span>
                  </div>
                  <div className="grid grid-3">
                    {group.map(u => (
                      <div key={u.id} className="card" style={{
                        opacity: u.is_active == 0 ? 0.5 : 1,
                        borderColor: u.is_active == 0 ? 'var(--border)' : undefined
                      }}>
                        <div style={{display:'flex',alignItems:'flex-start',gap:12}}>
                          <div style={{width:44,height:44,borderRadius:'50%',background:'linear-gradient(135deg,var(--primary),var(--accent))',display:'flex',alignItems:'center',justifyContent:'center',fontSize:'1rem',fontWeight:700,flexShrink:0}}>
                            {u.name.split(' ').map(n=>n[0]).join('').slice(0,2).toUpperCase()}
                          </div>
                          <div style={{flex:1,minWidth:0}}>
                            <div style={{fontWeight:700,fontSize:'0.95rem'}}>{u.name}</div>
                            <div className="text-muted text-xs truncate">{u.email}</div>
                            <div style={{marginTop:8,display:'flex',gap:6,flexWrap:'wrap',alignItems:'center'}}>
                              <span className={`badge ${u.is_active == 1 ? 'badge-interested' : 'badge-not-interested'}`}>
                                {u.is_active == 1 ? 'Active' : 'Inactive'}
                              </span>
                              <span className="text-xs text-muted">{u.lead_count} leads</span>
                            </div>
                          </div>
                        </div>
                        {u.last_login && <div className="text-xs text-muted mt-2">Last login: {new Date(u.last_login).toLocaleString('en-IN')}</div>}
                        <div style={{display:'flex',gap:8,marginTop:14,borderTop:'1px solid var(--border)',paddingTop:12}}>
                          <button className="btn btn-secondary btn-sm" style={{flex:1}} onClick={() => openEdit(u)}><Edit size={13}/> Edit</button>
                          <button className="btn btn-danger btn-sm" onClick={() => handleDelete(u)}><UserX size={13}/></button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )
            })
        }
      </div>

      {/* Create / Edit Modal */}
      {modal && (
        <div className="modal-overlay" onClick={() => setModal(null)}>
          <div className="modal" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h3>{modal === 'create' ? '👤 Add New User' : '✏️ Edit User'}</h3>
              <button className="modal-close" onClick={() => setModal(null)}><X size={18}/></button>
            </div>
            <form onSubmit={handleSave}>
              <div className="form-group">
                <label className="form-label">Full Name *</label>
                <input className="form-input" required value={form.name} onChange={e => setForm(f=>({...f,name:e.target.value}))} placeholder="e.g. Arjun Kumar"/>
              </div>
              <div className="form-group">
                <label className="form-label">Email Address *</label>
                <input className="form-input" type="email" required value={form.email} onChange={e => setForm(f=>({...f,email:e.target.value}))} placeholder="user@digital8x.site"/>
              </div>
              <div className="form-group">
                <label className="form-label">{modal === 'edit' ? 'New Password (leave blank to keep)' : 'Password *'}</label>
                <input className="form-input" type="password" required={modal==='create'} minLength={6}
                  value={form.password} onChange={e => setForm(f=>({...f,password:e.target.value}))} placeholder="Min. 6 characters"/>
              </div>
              <div className="grid grid-2">
                <div className="form-group">
                  <label className="form-label">Role *</label>
                  <select className="form-select" value={form.role} onChange={e => setForm(f=>({...f,role:e.target.value}))}>
                    {ROLES.map(r => <option key={r}>{r}</option>)}
                  </select>
                </div>
                {modal === 'edit' && (
                  <div className="form-group">
                    <label className="form-label">Status</label>
                    <select className="form-select" value={form.is_active ? '1' : '0'}
                      onChange={e => setForm(f=>({...f,is_active:e.target.value==='1'}))}>
                      <option value="1">Active</option>
                      <option value="0">Inactive</option>
                    </select>
                  </div>
                )}
              </div>
              <div className="modal-footer">
                <button type="button" className="btn btn-secondary" onClick={() => setModal(null)}>Cancel</button>
                <button type="submit" className="btn btn-primary" disabled={saving}>
                  {saving ? <span className="spinner" style={{width:16,height:16,borderWidth:2}}/> : null}
                  {saving ? 'Saving…' : modal === 'create' ? 'Create User' : 'Save Changes'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
