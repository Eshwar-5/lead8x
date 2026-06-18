import { useEffect, useState } from 'react'
import { getLeads, getUsers, distribute } from '../api/axios.js'
import { GitBranch, Users, RefreshCw, CheckSquare, X } from 'lucide-react'
import toast from 'react-hot-toast'

export default function Distribution() {
  const [batches, setBatches]   = useState([])
  const [users, setUsers]       = useState([])
  const [leads, setLeads]       = useState([])
  const [loading, setLoading]   = useState(true)
  const [mode, setMode]         = useState('equal') // 'equal' | 'manual'
  const [selectedBatch, setSelectedBatch] = useState('')
  const [selectedUsers, setSelectedUsers] = useState([])
  const [selectedLeads, setSelectedLeads] = useState([])
  const [submitting, setSubmitting] = useState(false)
  const [result, setResult]     = useState(null)

  const load = async () => {
    setLoading(true)
    try {
      const [uRes, lRes] = await Promise.all([getUsers(), getLeads({ limit: 200 })])
      setUsers(uRes.data.data.users.filter(u => ['Caller','Relationship Manager'].includes(u.role) && u.is_active == 1))
      const allLeads = lRes.data.data.leads
      setLeads(allLeads)
      // Extract unique batches
      const bMap = {}
      allLeads.forEach(l => { if (l.first_batch_id) bMap[l.first_batch_id] = (bMap[l.first_batch_id] || 0) + 1 })
      setBatches(Object.entries(bMap).map(([id, count]) => ({ id, count })))
    } catch { toast.error('Failed to load data.') }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  const toggleUser = (id) => {
    setSelectedUsers(s => s.includes(id) ? s.filter(x => x !== id) : [...s, id])
  }
  const toggleLead = (id) => {
    setSelectedLeads(s => s.includes(id) ? s.filter(x => x !== id) : [...s, id])
  }

  const handleDistribute = async () => {
    if (selectedUsers.length === 0) return toast.error('Select at least one caller.')
    if (mode === 'equal' && !selectedBatch) return toast.error('Select a batch for equal distribution.')
    if (mode === 'manual' && selectedLeads.length === 0) return toast.error('Select leads for manual distribution.')

    setSubmitting(true)
    try {
      const payload = {
        type:     mode,
        user_ids: selectedUsers,
        batch_id: mode === 'equal' ? selectedBatch : '',
        lead_ids: mode === 'manual' ? selectedLeads : [],
      }
      const res = await distribute(payload)
      setResult(res.data.data)
      toast.success(`✅ ${res.data.data.distributed} leads distributed!`)
      setSelectedLeads([]); setSelectedUsers([]); setSelectedBatch('')
      load()
    } catch (err) { toast.error(err.response?.data?.message || 'Distribution failed.') }
    finally { setSubmitting(false) }
  }

  const unassignedLeads = leads.filter(l => !l.assigned_to)

  return (
    <div>
      <div className="topbar">
        <h1>Lead Distribution</h1>
        <div className="topbar-actions">
          <button className="btn btn-secondary btn-sm" onClick={load}><RefreshCw size={14}/></button>
        </div>
      </div>

      <div className="page">
        {/* Mode toggle */}
        <div className="card mb-4">
          <div className="section-title"><GitBranch size={18} color="var(--accent)"/> Distribution Mode</div>
          <div className="flex gap-3 mt-2">
            <button
              id="mode-equal"
              className={`btn ${mode === 'equal' ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => { setMode('equal'); setSelectedLeads([]) }}
            >
              ⚖️ Equal Distribution
            </button>
            <button
              id="mode-manual"
              className={`btn ${mode === 'manual' ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => { setMode('manual'); setSelectedBatch('') }}
            >
              ✋ Manual Selection
            </button>
          </div>
          <p className="text-sm text-muted mt-2">
            {mode === 'equal'
              ? 'Select a batch and callers — leads split equally in round-robin order.'
              : 'Manually pick individual leads and assign to selected callers.'}
          </p>
        </div>

        <div className="grid grid-2 mb-4">
          {/* Step 1: Select leads source */}
          <div className="card">
            <div className="section-title">
              <CheckSquare size={17} color="var(--accent)"/>
              {mode === 'equal' ? 'Step 1: Select Batch' : 'Step 1: Select Leads'}
            </div>

            {mode === 'equal' ? (
              loading ? <div className="loading-overlay"><div className="spinner"/></div>
              : batches.length === 0
                ? <div className="empty-state"><p>No batches found.</p></div>
                : batches.map(b => (
                    <div
                      key={b.id}
                      onClick={() => setSelectedBatch(b.id)}
                      style={{
                        padding:'12px 16px', borderRadius:10, marginBottom:8, cursor:'pointer',
                        background: selectedBatch === b.id ? 'var(--primary-light)' : 'var(--bg-elevated)',
                        border: `1px solid ${selectedBatch === b.id ? 'var(--primary)' : 'var(--border)'}`,
                        transition:'all 0.15s'
                      }}
                    >
                      <div style={{display:'flex',justifyContent:'space-between'}}>
                        <strong style={{fontFamily:'monospace',fontSize:'0.85rem'}}>{b.id}</strong>
                        <span className="badge badge-new">{b.count} leads</span>
                      </div>
                    </div>
                  ))
            ) : (
              <div style={{maxHeight:320,overflowY:'auto'}}>
                {unassignedLeads.length === 0
                  ? <div className="empty-state"><p>No unassigned leads.</p></div>
                  : unassignedLeads.slice(0,100).map(l => (
                      <div key={l.id} onClick={() => toggleLead(l.id)}
                        style={{
                          display:'flex',alignItems:'center',gap:10,padding:'9px 12px',
                          borderRadius:8,marginBottom:4,cursor:'pointer',
                          background: selectedLeads.includes(l.id) ? 'var(--primary-light)' : 'var(--bg-elevated)',
                          border: `1px solid ${selectedLeads.includes(l.id) ? 'var(--primary)' : 'var(--border)'}`,
                        }}>
                        <input type="checkbox" readOnly checked={selectedLeads.includes(l.id)} style={{accentColor:'var(--primary)'}}/>
                        <span style={{fontFamily:'monospace',fontSize:'0.82rem'}}>{l.phone}</span>
                        <span className="text-muted text-xs">{l.name || '–'}</span>
                      </div>
                    ))
                }
                {selectedLeads.length > 0 && <div className="badge badge-assigned mt-2">{selectedLeads.length} selected</div>}
              </div>
            )}
          </div>

          {/* Step 2: Select callers */}
          <div className="card">
            <div className="section-title"><Users size={17} color="var(--accent)"/> Step 2: Select Callers</div>
            {loading ? <div className="loading-overlay"><div className="spinner"/></div>
            : users.length === 0
              ? <div className="empty-state"><p>No active callers found.</p></div>
              : users.map(u => (
                  <div key={u.id} onClick={() => toggleUser(u.id)}
                    style={{
                      display:'flex', alignItems:'center', gap:12, padding:'12px 14px',
                      borderRadius:10, marginBottom:6, cursor:'pointer',
                      background: selectedUsers.includes(u.id) ? 'var(--primary-light)' : 'var(--bg-elevated)',
                      border: `1px solid ${selectedUsers.includes(u.id) ? 'var(--primary)' : 'var(--border)'}`,
                      transition:'all 0.15s'
                    }}>
                    <input type="checkbox" readOnly checked={selectedUsers.includes(u.id)} style={{accentColor:'var(--primary)'}}/>
                    <div style={{width:34,height:34,borderRadius:'50%',background:'linear-gradient(135deg,var(--primary),var(--accent))',display:'flex',alignItems:'center',justifyContent:'center',fontSize:'0.78rem',fontWeight:700,flexShrink:0}}>
                      {u.name.split(' ').map(n=>n[0]).join('').slice(0,2).toUpperCase()}
                    </div>
                    <div style={{flex:1}}>
                      <div style={{fontWeight:600,fontSize:'0.875rem'}}>{u.name}</div>
                      <div style={{fontSize:'0.75rem',color:'var(--text-muted)'}}>{u.role} · {u.lead_count} leads</div>
                    </div>
                  </div>
                ))
            }
          </div>
        </div>

        {/* Preview & Submit */}
        {(selectedUsers.length > 0) && (selectedBatch || selectedLeads.length > 0) && (
          <div className="card mb-4" style={{borderColor:'var(--primary)',background:'var(--primary-light)'}}>
            <div style={{display:'flex',justifyContent:'space-between',alignItems:'center'}}>
              <div>
                <p style={{fontWeight:600}}>
                  {mode === 'equal'
                    ? `Batch <strong>${selectedBatch}</strong> → split among ${selectedUsers.length} caller(s)`
                    : `${selectedLeads.length} leads → split among ${selectedUsers.length} caller(s)`}
                </p>
                <p className="text-muted text-sm mt-1">
                  {mode === 'equal'
                    ? `~${Math.ceil((batches.find(b=>b.id===selectedBatch)?.count||0)/selectedUsers.length)} leads per person`
                    : `~${Math.ceil(selectedLeads.length/selectedUsers.length)} leads per person`}
                </p>
              </div>
              <button
                id="btn-distribute"
                className="btn btn-primary"
                onClick={handleDistribute}
                disabled={submitting}
              >
                {submitting ? <span className="spinner" style={{width:16,height:16,borderWidth:2}}/> : <GitBranch size={16}/>}
                {submitting ? 'Distributing…' : 'Distribute Now'}
              </button>
            </div>
          </div>
        )}

        {/* Result */}
        {result && (
          <div className="card">
            <div className="section-title" style={{color:'var(--success)'}}>✅ Distribution Complete</div>
            <p className="text-sm mb-4"><strong>{result.distributed}</strong> leads distributed.</p>
            <div className="grid grid-4">
              {Object.entries(result.per_user || {}).map(([name, count]) => (
                <div key={name} className="card card-sm" style={{textAlign:'center'}}>
                  <div style={{fontSize:'1.6rem',fontWeight:800,color:'var(--accent)'}}>{count}</div>
                  <div className="text-sm text-muted">{name}</div>
                </div>
              ))}
            </div>
            <button className="btn btn-secondary btn-sm mt-4" onClick={() => setResult(null)}><X size={13}/> Dismiss</button>
          </div>
        )}
      </div>
    </div>
  )
}
