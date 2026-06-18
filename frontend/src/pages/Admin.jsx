import { useEffect, useState } from 'react'
import { getStats, getActivityLog, downloadBackup, triggerDownload } from '../api/axios.js'
import { Shield, Download, RefreshCw, Activity } from 'lucide-react'
import toast from 'react-hot-toast'

export default function Admin() {
  const [logs, setLogs]       = useState([])
  const [logPage, setLogPage] = useState(1)
  const [logTotal, setLogTotal]  = useState(0)
  const [logPages, setLogPages]  = useState(1)
  const [loading, setLoading] = useState(true)
  const [backing, setBacking] = useState(false)
  const [overview, setOverview] = useState(null)

  const load = async (p = 1) => {
    setLoading(true)
    try {
      const [logRes, statRes] = await Promise.all([
        getActivityLog({ page: p, limit: 50 }),
        getStats()
      ])
      setLogs(logRes.data.data.logs)
      setLogTotal(logRes.data.data.total)
      setLogPages(logRes.data.data.total_pages)
      setLogPage(p)
      setOverview(statRes.data.data.overview)
    } catch { toast.error('Failed to load admin data.') }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  const handleBackup = async () => {
    setBacking(true)
    try {
      const res = await downloadBackup()
      triggerDownload(res.data, `Lead8X_Backup_${Date.now()}.zip`)
      toast.success('Backup downloaded successfully!')
    } catch { toast.error('Backup generation failed.') }
    finally { setBacking(false) }
  }

  return (
    <div>
      <div className="topbar">
        <h1>Admin Panel</h1>
        <div className="topbar-actions">
          <button
            id="btn-backup"
            className="btn btn-success"
            onClick={handleBackup}
            disabled={backing}
          >
            {backing ? <span className="spinner" style={{width:16,height:16,borderWidth:2}}/> : <Download size={15}/>}
            {backing ? 'Generating…' : 'Backup Now'}
          </button>
          <button className="btn btn-secondary btn-sm" onClick={() => load(logPage)}><RefreshCw size={14}/></button>
        </div>
      </div>

      <div className="page">
        {/* System Overview */}
        {overview && (
          <div className="grid grid-4 mb-6">
            {[
              { label:'Total Leads',    value: overview.total_leads?.toLocaleString(),     color:'#7c3aed' },
              { label:'Assigned',       value: overview.assigned_leads?.toLocaleString(),   color:'#10b981' },
              { label:'Duplicates',     value: overview.duplicate_leads?.toLocaleString(),  color:'#f59e0b' },
              { label:'Active Users',   value: overview.total_users?.toLocaleString(),       color:'#06b6d4' },
            ].map((item,i) => (
              <div className="stat-card" key={i}>
                <div className="stat-content">
                  <div className="stat-value" style={{color:item.color}}>{item.value ?? '–'}</div>
                  <div className="stat-label">{item.label}</div>
                </div>
              </div>
            ))}
          </div>
        )}


        {/* Activity Log */}
        <div className="card">
          <div className="section-title"><Activity size={17} color="var(--accent)"/> Activity Log
            <span className="text-muted text-sm" style={{marginLeft:'auto',fontWeight:400}}>Total: {logTotal}</span>
          </div>

          {loading
            ? <div className="loading-overlay"><div className="spinner"/></div>
            : <div className="table-wrapper">
                <table>
                  <thead>
                    <tr><th>Time</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr>
                  </thead>
                  <tbody>
                    {logs.map(log => (
                      <tr key={log.id}>
                        <td className="text-xs text-muted" style={{whiteSpace:'nowrap'}}>
                          {new Date(log.created_at).toLocaleString('en-IN')}
                        </td>
                        <td><strong>{log.user_name || 'System'}</strong></td>
                        <td>
                          <span className="badge badge-assigned">{log.action}</span>
                        </td>
                        <td className="text-sm text-muted">{log.description}</td>
                        <td className="text-xs text-muted" style={{fontFamily:'monospace'}}>{log.ip_address}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
          }

          {/* Pagination */}
          {logPages > 1 && (
            <div className="pagination mt-4">
              <button className="page-btn" disabled={logPage===1} onClick={() => load(1)}>«</button>
              <button className="page-btn" disabled={logPage===1} onClick={() => load(logPage-1)}>‹</button>
              <span className="page-btn active">{logPage} / {logPages}</span>
              <button className="page-btn" disabled={logPage===logPages} onClick={() => load(logPage+1)}>›</button>
              <button className="page-btn" disabled={logPage===logPages} onClick={() => load(logPages)}>»</button>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
