import { useEffect, useState } from 'react'
import { getWebhookLogs } from '../api/axios.js'
import { Activity, RefreshCw, Eye, AlertCircle, CheckCircle, Clock } from 'lucide-react'
import toast from 'react-hot-toast'

export default function WebhookLog() {
  const [logs, setLogs] = useState([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const [selectedPayload, setSelectedPayload] = useState(null)

  const load = async (p = 1) => {
    setLoading(true)
    try {
      const res = await getWebhookLogs({ page: p, limit: 15 })
      setLogs(res.data.data.logs)
      setTotalPages(res.data.data.total_pages)
      setPage(p)
    } catch { toast.error('Failed to load webhook logs.') }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  const getStatusBadge = (status) => {
    switch(status) {
      case 'processed': return <span className="badge badge-assigned"><CheckCircle size={12}/> Processed</span>
      case 'duplicate': return <span className="badge badge-followup"><Clock size={12}/> Duplicate</span>
      case 'failed':    return <span className="badge badge-danger"><AlertCircle size={12}/> Failed</span>
      default:          return <span className="badge badge-new">Received</span>
    }
  }

  return (
    <div>
      <div className="topbar">
        <h1>Webhook Processing Logs</h1>
        <div className="topbar-actions">
          <button className="btn btn-secondary btn-sm" onClick={() => load(page)}><RefreshCw size={14} className={loading ? 'animate-spin' : ''}/></button>
        </div>
      </div>

      <div className="page">
        <div className="card">
          <div className="section-title"><Activity size={17} color="var(--accent)"/> Processing History</div>

          {loading ? (
            <div className="loading-overlay"><div className="spinner"/></div>
          ) : (
            <div className="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Time Received</th>
                    <th>Platform</th>
                    <th>Status</th>
                    <th>Linked Lead</th>
                    <th>Payload</th>
                  </tr>
                </thead>
                <tbody>
                  {logs.map(log => (
                    <tr key={log.id}>
                      <td className="text-sm text-muted">{new Date(log.created_at).toLocaleString('en-IN')}</td>
                      <td><span className="capitalize font-bold">{log.platform}</span></td>
                      <td>
                        {getStatusBadge(log.status)}
                        {log.status === 'failed' && log.error_message && (
                          <div className="text-xs text-danger mt-1" style={{maxWidth: 150}}>
                            {log.error_message}
                          </div>
                        )}
                      </td>
                      <td>
                        {log.lead_id ? (
                           <a href={`/leads?id=${log.lead_id}`} className="link font-mono text-xs"># {log.lead_id}</a>
                        ) : '—'}
                      </td>
                      <td>
                        <button className="btn btn-secondary btn-sm" onClick={() => {
                          try {
                            const parsed = JSON.parse(log.raw_payload);
                            setSelectedPayload(parsed);
                          } catch (e) {
                            toast.error('Invalid payload JSON format');
                            setSelectedPayload(null);
                          }
                        }}>
                          <Eye size={12}/> View Raw
                        </button>
                      </td>
                    </tr>
                  ))}
                  {logs.length === 0 && <tr><td colSpan="5" className="text-center py-8">No logs found.</td></tr>}
                </tbody>
              </table>
            </div>
          )}

          {totalPages > 1 && (
            <div className="pagination mt-4">
              <button className="page-btn" disabled={page === 1} onClick={() => load(page - 1)}>‹</button>
              <span className="page-btn active">{page} / {totalPages}</span>
              <button className="page-btn" disabled={page === totalPages} onClick={() => load(page + 1)}>›</button>
            </div>
          )}
        </div>

        {selectedPayload && (
          <div className="modal-overlay" onClick={() => setSelectedPayload(null)}>
            <div className="card modal-content" onClick={e => e.stopPropagation()} style={{maxWidth:600, maxHeight:'80vh', overflow:'auto'}}>
              <div className="flex justify-between items-center mb-4">
                <h3 className="font-bold">Raw Payload Data</h3>
                <button className="btn btn-secondary btn-sm" onClick={() => setSelectedPayload(null)}>Close</button>
              </div>
              <pre className="text-xs bg-dark p-4 rounded overflow-auto" style={{background:'#0f0f1a'}}>
                {JSON.stringify(selectedPayload, null, 2)}
              </pre>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
