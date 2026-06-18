import { useEffect, useState } from 'react'
import { getLeads, downloadLeads, triggerDownload } from '../api/axios.js'
import { Filter, Download, Search, LayoutGrid, List as ListIcon, Zap } from 'lucide-react'
import toast from 'react-hot-toast'

export default function AutoLeads() {
  const [leads, setLeads] = useState([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const [totalLeads, setTotalLeads] = useState(0)

  const load = async (p = 1) => {
    setLoading(true)
    try {
      // Filter for auto_imported = 1
      const res = await getLeads({ 
        page: p, 
        limit: 25, 
        auto_imported: 1 
      })
      setLeads(res.data.data.leads)
      setTotalPages(res.data.data.total_pages)
      setPage(p)
      setTotalLeads(res.data.data.total)
    } catch { toast.error('Failed to load auto-imported leads.') }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  const handleDownload = async () => {
    try {
       const res = await downloadLeads({ auto_imported: 1 })
       triggerDownload(res.data, `Auto_Imported_Leads_${Date.now()}.xlsx`)
       toast.success('File downloaded successfully.')
    } catch { toast.error('Download failed.') }
  }

  return (
    <div>
      <div className="topbar">
        <h1 className="flex items-center gap-2 text-warning">
          <Zap size={24} fill="currentColor"/> Real-time Ad Leads
        </h1>
        <div className="topbar-actions">
           <button className="btn btn-secondary" onClick={handleDownload}><Download size={16}/> Export Excel</button>
        </div>
      </div>

      <div className="page">
        <div className="card">
          <div className="section-title">
            Showing Auto-Imported Leads (System Processed)
            <span className="text-muted text-sm ml-auto">Total: {totalLeads}</span>
          </div>

          {loading ? (
             <div className="loading-overlay"><div className="spinner"/></div>
          ) : (
            <div className="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Platform</th>
                    <th>Campaign</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {leads.map(lead => (
                    <tr key={lead.id}>
                      <td className="text-sm text-muted">{new Date(lead.created_at).toLocaleDateString()}</td>
                      <td><strong>{lead.name || '—'}</strong></td>
                      <td className="font-mono">{lead.phone}</td>
                      <td><span className="badge badge-assigned">{lead.first_source}</span></td>
                      <td className="text-xs text-muted truncate max-w-[150px]">{lead.campaign_id || lead.first_batch_id}</td>
                      <td>
                        <span className={`badge badge-${lead.status?.toLowerCase().replace(/\s+/g, '')}`}>
                          {lead.status}
                        </span>
                      </td>
                    </tr>
                  ))}
                  {leads.length === 0 && <tr><td colSpan="6" className="text-center py-12">No real-time leads received yet.</td></tr>}
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
      </div>
    </div>
  )
}
