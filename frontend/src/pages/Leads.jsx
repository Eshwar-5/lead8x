import { useState, useEffect, useCallback, useRef } from 'react'
import { useLocation as useRouterLocation } from 'react-router-dom'
import {
  getLeads, uploadLeadsPreview, confirmUpload, updateFeedback, uploadFeedback,
  getTimeline, deleteLeads, mergeLeads, getProjects, getUsers, getAllLocations,
  triggerDownload, downloadLeads, getDevices
} from '../api/axios.js'
import toast from 'react-hot-toast'
import {
  Search, Upload, Download, X, ChevronLeft, ChevronRight,
  Trash2, GitMerge, Globe, Eye, FileText, CheckSquare, Square,
  AlertTriangle, RefreshCw, ArrowUp, ArrowDown, ArrowUpDown, RefreshCcw, MapPin
} from 'lucide-react'

const STATUSES   = ['New','Assigned','Called','Interested','Follow Up','Site Visit','Booked','Not Interested','Wrong Number']
const PAGE_SIZES = [50, 100, 200, 500, 1000]
const DEVICES    = [{ label:'All Devices', val:'' }, { label:'Safari | iPhone', val:'Safari' }, { label:'Chrome | Windows', val:'Chrome' }]

/* ── Sortable header ─────────────────────────────────── */
function SortIcon({ col, sortBy, sortDir }) {
  if (sortBy !== col) return <ArrowUpDown size={11} style={{ opacity:0.3, marginLeft:2 }} />
  return sortDir === 'ASC'
    ? <ArrowUp   size={11} style={{ marginLeft:2, color:'var(--primary)' }} />
    : <ArrowDown size={11} style={{ marginLeft:2, color:'var(--primary)' }} />
}
function Th({ label, col, width, sortBy, setSortBy, sortDir, setSortDir, style={} }) {
  const toggle = () => {
    if (sortBy === col) setSortDir(d => d === 'DESC' ? 'ASC' : 'DESC')
    else { setSortBy(col); setSortDir('DESC') }
  }
  return (
    <th onClick={toggle} style={{ cursor:'pointer', userSelect:'none', width, ...style }}>
      {label}<SortIcon col={col} sortBy={sortBy} sortDir={sortDir} />
    </th>
  )
}

/* ── Status badge ────────────────────────────────────── */
function StatusBadge({ status }) {
  const map = {
    'New':'badge-new','Assigned':'badge-assigned','Called':'badge-called',
    'Interested':'badge-interested','Follow Up':'badge-follow-up','Site Visit':'badge-site-visit',
    'Booked':'badge-booked','Not Interested':'badge-not-interested','Wrong Number':'badge-wrong-number'
  }
  return <span className={`badge ${map[status] || 'badge-new'}`} style={{ fontSize:'0.68rem', padding:'2px 6px' }}>{status}</span>
}

/* ── cell style helpers ──────────────────────────────── */
const tdStyle = { overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap', fontSize:'0.76rem', padding:'6px 8px' }
const thStyle = { fontSize:'0.74rem', padding:'8px 8px', whiteSpace:'nowrap' }

export default function Leads() {
  const user    = JSON.parse(localStorage.getItem('lead8x_user') || '{}')
  const isAdmin = ['Admin','Manager'].includes(user.role)

  // Table state
  const [leads, setLeads]           = useState([])
  const [total, setTotal]           = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [page, setPage]             = useState(1)
  const [limit, setLimit]           = useState(50)
  const [loading, setLoading]       = useState(false)
  const [selected, setSelected]     = useState([])

  // Filters
  const [search, setSearch]         = useState('')
  const [status, setStatus]         = useState('')
  const [project, setProject]       = useState('')
  const [location, setLocation]     = useState('')
  const [device, setDevice]         = useState('')
  const [dateFrom, setDateFrom]     = useState('')
  const [dateTo, setDateTo]         = useState('')
  const [isNri, setIsNri]           = useState(false)
  const [showDuplicates, setShowDuplicates] = useState(false)
  const [showDeleted, setShowDeleted]       = useState(false)
  const [assignedOnly, setAssignedOnly]         = useState(false)
  const [unassignedOnly, setUnassignedOnly]     = useState(false)

  // Sort
  const [sortBy, setSortBy]   = useState('date')
  const [sortDir, setSortDir] = useState('DESC')

  // Reference data
  const [allLocations, setAllLocations] = useState([])   // all distinct location names
  const [projects, setProjects]         = useState([])   // projects scoped to current location (or all)
  const [users, setUsers]               = useState([])
  const [devicesList, setDevicesList]   = useState([])

  // Upload
  const [previewData, setPreviewData] = useState(null)
  const [uploading, setUploading]     = useState(false)
  const [confirming, setConfirming]   = useState(false)
  const [projName, setProjName]       = useState('')
  const [referUrl, setReferUrl]       = useState('')
  const fbInputRef = useRef(null)

  // Modals
  const [timelineModal, setTimelineModal] = useState(null)
  const [timeline, setTimeline]           = useState([])
  const [feedbackModal, setFeedbackModal] = useState(null)
  const [feedbackForm, setFeedbackForm]   = useState({ status:'', remark:'' })
  const [deleteConfirm, setDeleteConfirm] = useState(null)
  const [merging, setMerging]             = useState(false)

  /* ── Load leads ──────────────────────────────────── */  /* ── Drill-down from Dashboard navigation ── */
  const routerLocation = useRouterLocation()
  useEffect(() => {
    const d = routerLocation.state?.drilldown
    if (!d) return
    if (d.is_duplicate)    setShowDuplicates(true)
    if (d.is_nri === 1)    setIsNri(true)
    if (d.project)         { setProject(d.project);  setPage(1) }
    if (d.device)          { setDevice(d.device);    setPage(1) }
    if (d.location)        { setLocation(d.location); setPage(1) }
    if (d.status)          { setStatus(d.status);    setPage(1) }
    if (d.country)         { setPage(1) /* country filter applied via params below */ }
    if (d.assigned_only)   setAssignedOnly(true)
    if (d.unassigned_only) setUnassignedOnly(true)
    // Store country for params (need a state slot)
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  /* ── Country from drill-down (own state) ── */
  const [drillCountry, setDrillCountry] = useState('')
  useEffect(() => {
    const d = routerLocation.state?.drilldown
    if (d?.country) setDrillCountry(d.country)
  }, []) // eslint-disable-line react-hooks/exhaustive-deps
  const loadLeads = useCallback(async () => {
    setLoading(true)
    try {
      const params = { page, limit, search, status, project, device,
        sort_by: sortBy, sort_dir: sortDir }
      if (location)        params.location      = location
      if (isNri)           params.is_nri        = 1
      if (showDuplicates)  params.is_duplicate  = 1
      if (showDeleted)     params.show_deleted  = 1
      if (dateFrom)        params.date_from     = dateFrom
      if (dateTo)          params.date_to       = dateTo
      if (assignedOnly)    params.assigned_only = 1
      if (unassignedOnly)  params.unassigned_only = 1
      if (drillCountry)    params.country       = drillCountry
      const res = await getLeads(params)
      const data = res.data?.data || {}
      setLeads(Array.isArray(data.leads) ? data.leads : [])
      setTotal(Number(data.total) || 0)
      setTotalPages(Number(data.total_pages) || 1)
      setSelected([])
    } catch { toast.error('Failed to load leads.') }
    setLoading(false)
  }, [page, limit, search, status, project, location, device, isNri, showDuplicates, showDeleted, dateFrom, dateTo, sortBy, sortDir, assignedOnly, unassignedOnly, drillCountry])

  useEffect(() => { loadLeads() }, [loadLeads])

  /* ── Load ALL distinct location names once (for Location filter) ── */
  const loadAllLocations = useCallback(() => {
    getAllLocations()
      .then(r => setAllLocations(r.data.data.locations || []))
      .catch(() => setAllLocations([]))
  }, [])

  /* ── Load project names — scoped to selected location or all ── */
  const loadProjects = useCallback((loc) => {
    const params = loc ? { mode: 'by_location', location: loc } : undefined
    getProjects(params)
      .then(r => setProjects(r.data.data.projects || []))
      .catch(() => setProjects([]))
  }, [])

  /* ── On mount: load locations + all projects (no location selected yet) ── */
  useEffect(() => {
    loadAllLocations()
    loadProjects(null)  // load all active-lead projects initially
    getDevices().then(r => setDevicesList(r.data?.data?.devices || [])).catch(()=>{})
    if (isAdmin) getUsers().then(r => setUsers(r.data.data.users || [])).catch(() => {})
  }, [isAdmin, loadAllLocations, loadProjects])

  /* ── When location filter changes: refresh project list ── */
  useEffect(() => {
    setProject('')  // reset project selection whenever location changes
    loadProjects(location || null)
  }, [location, loadProjects])

  /* ── Upload step 1 ───────────────────────────────── */
  const handleFileChange = async (e) => {
    const files = Array.from(e.target.files); if (!files.length) return
    setUploading(true)
    try {
      const allPreviews = []
      for (const file of files) {
        const fd = new FormData(); fd.append('file', file)
        const res = await uploadLeadsPreview(fd)
        allPreviews.push({ ...res.data.data, filename: file.name })
      }
      const aggregated = {
        previews: allPreviews,
        total_rows: allPreviews.reduce((s, p) => s + p.total_rows, 0),
        hidden_values: [...new Set(allPreviews.flatMap(p => p.hidden_values || []))],
        preview: allPreviews.flatMap(p => p.preview || []).slice(0, 20),
        refer_detected: allPreviews.some(p => p.refer_detected),
        files_count: files.length
      }
      setPreviewData(aggregated); setProjName(aggregated.hidden_values?.[0] || ''); setReferUrl('')
    } catch (err) { toast.error(err.response?.data?.message || 'Upload failed.') }
    setUploading(false); e.target.value = ''
  }

  /* ── Upload step 2 ───────────────────────────────── */
  const handleConfirmUpload = async () => {
    if (!projName.trim()) { toast.error('Project Name is required.'); return }
    setConfirming(true)
    let totalNew = 0, totalDups = 0, failed = 0;
    
    // Process sequentially so one failure doesn't break the rest
    for (const p of previewData.previews) {
      try {
        const res = await confirmUpload({ parse_id: p.parse_id, project_name: projName.trim(), refer_url: referUrl.trim() })
        const d = res.data.data
        totalNew += (d.new || 0); totalDups += (d.duplicates || 0);
      } catch (err) {
        toast.error(`Confirmation failed for ${p.filename}.`)
        failed++;
      }
    }
    
    if (totalNew > 0 || totalDups > 0) {
      toast.success(`✅ ${totalNew} leads imported! ${totalDups} duplicates.`);
    }
    if (failed > 0) {
      toast.error(`${failed} file(s) failed to import.`);
    }
    
    setPreviewData(null); loadLeads(); loadProjects(location || null); loadAllLocations();
    setConfirming(false)
  }

  /* ── Feedback sync upload ────────────────────────── */
  const handleFeedbackSync = async (e) => {
    const file = e.target.files[0]; if (!file) return
    const fd = new FormData(); fd.append('file', file)
    const t = toast.loading('Syncing feedback…')
    try {
      const res = await uploadFeedback(fd)
      const d = res.data.data
      toast.success(`✅ ${d.updated} leads updated from ${d.processed} rows.`, { id: t })
      loadLeads()
    } catch (err) {
      toast.error(err.response?.data?.message || 'Sync failed.', { id: t })
    }
    e.target.value = ''
  }

  /* ── Download helpers ────────────────────────────── */
  const handleDownloadSelection = async () => {
    if (selected.length === 0) { toast.error('Select leads first.'); return }
    try {
      const res = await downloadLeads({ export_ids: selected.join(',') })
      triggerDownload(res.data, 'leads_selection.xlsx')
    } catch { toast.error('Export failed.') }
  }
  const handleDownloadAll = async () => {
    try {
      // Pass all active filters so export matches exactly what is shown on screen
      const params = {
        search, status, project,
        ...(location  ? { location }        : {}),
        ...(device    ? { device }           : {}),
        ...(isNri     ? { is_nri: 1 }        : {}),
        ...(dateFrom  ? { date_from: dateFrom } : {}),
        ...(dateTo    ? { date_to: dateTo }     : {}),
      }
      const res = await downloadLeads(params)
      triggerDownload(res.data, 'leads_export.xlsx')
    } catch { toast.error('Export failed.') }
  }

  /* ── Timeline ────────────────────────────────────── */
  const openTimeline = async (lead) => {
    setTimelineModal(lead)
    try { const res = await getTimeline(lead.id); setTimeline(res.data.data.timeline || []) }
    catch { setTimeline([]) }
  }

  /* ── Feedback modal save ─────────────────────────── */
  const submitFeedback = async () => {
    try {
      await updateFeedback({ lead_id: feedbackModal.id, ...feedbackForm })
      toast.success('Feedback saved.'); setFeedbackModal(null); loadLeads()
    } catch { toast.error('Failed to save feedback.') }
  }

  /* ── In-row quick status ─────────────────────────── */
  const quickStatus = async (leadId, newStatus) => {
    try {
      await updateFeedback({ lead_id: leadId, status: newStatus })
      setLeads(ls => ls.map(l => l.id === leadId ? { ...l, status: newStatus } : l))
    } catch { toast.error('Update failed.'); loadLeads() }
  }

  /* ── In-row assign ───────────────────────────────── */
  const quickAssign = async (leadId, uid) => {
    try {
      const assignedTo = uid ? parseInt(uid) : null
      await updateFeedback({ lead_id: leadId, assigned_to: assignedTo })
      setLeads(ls => ls.map(l => {
        if (l.id !== leadId) return l
        const u = users.find(u => u.id === assignedTo)
        return { ...l, assigned_to: assignedTo, assigned_to_name: u?.name || '' }
      }))
      toast.success('Assigned.')
    } catch { toast.error('Assignment failed.') }
  }

  /* ── Selection ───────────────────────────────────── */
  const toggleSelect = (id) =>
    setSelected(s => s.includes(id) ? s.filter(x => x !== id) : [...s, id])
  const toggleAll = () =>
    setSelected(s => s.length === leads.length ? [] : leads.map(l => l.id))

  /* ── Delete / purge ──────────────────────────────── */
  const confirmDelete = async () => {
    if (!deleteConfirm) return
    try {
      await deleteLeads(deleteConfirm)
      toast.success(['purge','purge_all'].includes(deleteConfirm.mode) ? 'Permanently deleted.' : 'Moved to trash.')
      setDeleteConfirm(null); setSelected([]); loadLeads()
      loadProjects(location || null); loadAllLocations()
    } catch { toast.error('Delete failed.') }
  }

  /* ── Merge ───────────────────────────────────────── */
  const handleMerge = async () => {
    if (selected.length < 2) { toast.error('Select at least 2 leads.'); return }
    setMerging(true)
    try {
      const res = await mergeLeads(selected)
      toast.success(`✅ Merged into lead #${res.data.data.master_id}`)
      setSelected([]); loadLeads()
    } catch (err) { toast.error(err.response?.data?.message || 'Merge failed.') }
    setMerging(false)
  }

  const fmt     = (v) => v || '—'
  const fmtDate = (v) => v ? new Date(v).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'2-digit' }) : '—'
  const resetFilters = () => {
    setSearch(''); setStatus(''); setProject(''); setLocation(''); setDevice('');
    setDateFrom(''); setDateTo(''); setIsNri(false);
    setShowDuplicates(false); setShowDeleted(false); setPage(1)
  }

  /* ── COMPACT SELECT STYLE ────────────────────────── */
  const cs = { padding:'2px 4px', fontSize:'0.72rem', width:'100%', border:'1px solid var(--border)', borderRadius:4, background:'var(--bg-elevated)', color:'var(--text-primary)' }

  return (
    <div className="page" style={{ padding:'16px 16px 32px' }}>

      {/* ── HEADER ── */}
      <div className="topbar" style={{ marginBottom:12, flexWrap:'wrap', gap:8 }}>
        <h1 style={{ fontSize:'1.2rem' }}>Leads <span style={{ fontSize:'0.78rem', color:'var(--text-muted)', fontWeight:400 }}>({total.toLocaleString()})</span></h1>
        <div style={{ display:'flex', flexWrap:'wrap', gap:6 }}>
          {isAdmin && (
            <>
              <label className="btn btn-primary btn-sm" style={{ cursor:'pointer', fontSize:'0.78rem' }}>
                <Upload size={13} /> {uploading ? 'Parsing…' : 'Upload'}
                <input type="file" accept=".xlsx,.xls,.csv" hidden onChange={handleFileChange} disabled={uploading} multiple />
              </label>
              <label className="btn btn-secondary btn-sm" style={{ cursor:'pointer', fontSize:'0.78rem' }} title="Re-upload exported sheet with ID+Status+Remarks">
                <RefreshCcw size={13} /> Feedback Sync
                <input type="file" ref={fbInputRef} accept=".xlsx,.xls,.csv" hidden onChange={handleFeedbackSync} />
              </label>
              <button className="btn btn-secondary btn-sm" style={{ fontSize:'0.78rem' }} onClick={handleDownloadSelection}><Download size={13} /> Selection</button>
              <button className="btn btn-secondary btn-sm" style={{ fontSize:'0.78rem' }} onClick={handleDownloadAll}><Download size={13} /> Export All</button>
            </>
          )}
        </div>
      </div>

      {/* ── FILTER BAR ── */}
      <div style={{ display:'flex', flexWrap:'wrap', gap:6, marginBottom:8 }}>

        {/* SEARCH */}
        <div className="search-box" style={{ flex:'1 1 160px', minWidth:140 }}>
          <Search size={13} />
          <input className="form-input" style={{ fontSize:'0.8rem', padding:'5px 8px' }}
            placeholder="ID, phone, name…" value={search}
            onChange={e => { setSearch(e.target.value); setPage(1) }} />
        </div>

        {/* STATUS */}
        <select className="form-select" style={{ flex:'0 0 120px', fontSize:'0.78rem', padding:'4px 6px' }} value={status}
          onChange={e => { setStatus(e.target.value); setPage(1) }}>
          <option value="">All Statuses</option>
          {STATUSES.map(s => <option key={s}>{s}</option>)}
        </select>

        {/* ── LOCATION FILTER — always visible and independent ── */}
        {isAdmin && (
          <select
            className="form-select"
            style={{ flex:'0 0 150px', fontSize:'0.78rem', padding:'4px 6px',
              borderColor: location ? 'var(--primary)' : undefined,
              fontWeight: location ? 600 : undefined }}
            value={location}
            onChange={e => { setLocation(e.target.value); setPage(1) }}
            title="Filter by Location"
          >
            <option value="">📍 All Locations</option>
            {allLocations.map(loc => (
              <option key={loc} value={loc}>{loc}</option>
            ))}
          </select>
        )}

        {/* ── PROJECT NAME FILTER — dynamically scoped to selected location ── */}
        {isAdmin && (
          <select
            className="form-select"
            style={{ flex:'0 0 140px', fontSize:'0.78rem', padding:'4px 6px',
              borderColor: project ? 'var(--primary)' : undefined }}
            value={project}
            onChange={e => { setProject(e.target.value); setPage(1) }}
          >
            <option value="">{location ? `All (${projects.length})` : 'All Projects'}</option>
            {projects.map(p => <option key={p.id} value={p.name}>{p.name}</option>)}
          </select>
        )}

        {/* DEVICE */}
        <select className="form-select" style={{ flex:'0 0 118px', fontSize:'0.78rem', padding:'4px 6px' }} value={device}
          onChange={e => { setDevice(e.target.value); setPage(1) }}>
          <option value="">All Devices</option>
          {devicesList.map(d => <option key={d.name} value={d.name}>{d.name}</option>)}
        </select>

        {/* DATE RANGE */}
        <input type="date" className="form-input" style={{ flex:'0 0 120px', fontSize:'0.78rem', padding:'4px 6px' }}
          value={dateFrom} onChange={e => { setDateFrom(e.target.value); setPage(1) }} title="From date" />
        <input type="date" className="form-input" style={{ flex:'0 0 120px', fontSize:'0.78rem', padding:'4px 6px' }}
          value={dateTo} onChange={e => { setDateTo(e.target.value); setPage(1) }} title="To date" />

        {/* FLAG BUTTONS */}
        <button className={`btn btn-sm ${isNri ? 'btn-primary' : 'btn-secondary'}`} style={{ fontSize:'0.75rem' }}
          onClick={() => { setIsNri(v => !v); setPage(1) }}><Globe size={12} /> NRI</button>
        <button className={`btn btn-sm ${showDuplicates ? 'btn-primary' : 'btn-secondary'}`} style={{ fontSize:'0.75rem' }}
          onClick={() => { setShowDuplicates(v => !v); setPage(1) }}><RefreshCw size={12} /> Dups</button>
        {isAdmin && (
          <button className={`btn btn-sm ${showDeleted ? 'btn-danger' : 'btn-secondary'}`} style={{ fontSize:'0.75rem' }}
            onClick={() => { setShowDeleted(v => !v); setPage(1) }}>
            <Trash2 size={12} /> {showDeleted ? 'Trash View' : 'Trash'}
          </button>
        )}

        {/* PAGE SIZE */}
        <select className="form-select" style={{ flex:'0 0 90px', fontSize:'0.78rem', padding:'4px 6px' }} value={limit}
          onChange={e => { setLimit(parseInt(e.target.value)); setPage(1) }}>
          {PAGE_SIZES.map(n => <option key={n} value={n}>{n}/pg</option>)}
        </select>

        {/* RESET */}
        <button className="btn btn-secondary btn-sm" style={{ fontSize:'0.75rem' }} onClick={resetFilters} title="Reset filters">↺ Reset</button>
      </div>

      {/* ── ACTIVE FILTER INDICATOR ── */}
      {(location || project) && (
        <div style={{ display:'flex', gap:6, marginBottom:8, flexWrap:'wrap' }}>
          {location && (
            <span style={{ display:'inline-flex', alignItems:'center', gap:4, background:'var(--primary-light)',
              color:'var(--primary)', borderRadius:12, padding:'2px 10px', fontSize:'0.75rem', fontWeight:600 }}>
              <MapPin size={11} /> {location}
              <button onClick={() => { setLocation(''); setPage(1) }}
                style={{ background:'none', border:'none', cursor:'pointer', color:'var(--primary)', padding:0, marginLeft:2, display:'flex' }}>
                <X size={11} />
              </button>
            </span>
          )}
          {project && (
            <span style={{ display:'inline-flex', alignItems:'center', gap:4, background:'var(--primary-light)',
              color:'var(--primary)', borderRadius:12, padding:'2px 10px', fontSize:'0.75rem', fontWeight:600 }}>
              {project}
              <button onClick={() => { setProject(''); setPage(1) }}
                style={{ background:'none', border:'none', cursor:'pointer', color:'var(--primary)', padding:0, marginLeft:2, display:'flex' }}>
                <X size={11} />
              </button>
            </span>
          )}
        </div>
      )}

      {/* ── BULK / ADMIN TOOLS ── */}
      {isAdmin && (
        <div style={{ display:'flex', flexWrap:'wrap', gap:6, alignItems:'center', marginBottom:10 }}>
          {selected.length > 0 && (
            <>
              <span style={{ fontSize:'0.8rem', fontWeight:600, color:'var(--accent)' }}>{selected.length} sel.</span>
              {!showDeleted && <button className="btn btn-danger btn-sm" style={{ fontSize:'0.75rem' }} onClick={() => setDeleteConfirm({ mode:'bulk', ids:selected })}><Trash2 size={12}/> Trash</button>}
              {showDeleted  && <button className="btn btn-danger btn-sm" style={{ fontSize:'0.75rem' }} onClick={() => setDeleteConfirm({ mode:'purge', ids:selected })}><Trash2 size={12}/> Purge</button>}
              {showDuplicates && <button className="btn btn-sm" style={{ fontSize:'0.75rem', background:'var(--primary-light)', color:'var(--accent)' }} onClick={handleMerge} disabled={merging}><GitMerge size={12}/> Merge</button>}
              <button className="btn btn-secondary btn-sm" style={{ fontSize:'0.75rem' }} onClick={() => setSelected([])}><X size={12}/></button>
              <span style={{ borderLeft:'1px solid var(--border)', height:20, margin:'0 4px' }}/>
            </>
          )}
          {!showDeleted && (
            <></>
          )}
          {showDeleted && (
            <button className="btn btn-danger btn-sm" style={{ fontSize:'0.75rem' }} onClick={() => setDeleteConfirm({ mode:'purge_all' })}>
              <Trash2 size={12}/> Purge Entire Trash
            </button>
          )}
        </div>
      )}

      {/* ── TABLE ── */}
      <div style={{ width:'100%', overflowX:'auto' }}>
        <table style={{ tableLayout:'fixed', width:'100%', borderCollapse:'collapse' }}>
          <colgroup>
            {isAdmin && <col style={{ width:30 }} />}
            <col style={{ width:40 }} />
            <col style={{ width:88 }} />
            <col style={{ width:95 }} />
            {isAdmin && <col style={{ width:88 }} />}{/* Project */}
            {isAdmin && <col style={{ width:120 }} />}{/* Assigned */}
            {isAdmin && <col style={{ width:70 }} />}
            <col style={{ width:115 }} />
            {isAdmin && <col style={{ width:60 }} />}{/* Country - admin only */}
            {isAdmin && <col style={{ width:82 }} />}{/* IP - admin only */}
            <col style={{ width:88 }} />
            <col style={{ width:88 }} />
            <col style={{ width:76 }} />
          </colgroup>
          <thead>
            <tr>
              {isAdmin && (
                <th style={{ ...thStyle, width:30 }}>
                  <button style={{ background:'none', border:'none', cursor:'pointer', color:'var(--text-muted)', padding:0 }} onClick={toggleAll}>
                    {selected.length === leads.length && leads.length > 0 ? <CheckSquare size={14}/> : <Square size={14}/>}
                  </button>
                </th>
              )}
              <Th label="ID"      col="id"       width={40}  sortBy={sortBy} setSortBy={setSortBy} sortDir={sortDir} setSortDir={setSortDir} style={thStyle}/>
              <Th label="Name"    col="name"     width={88}  sortBy={sortBy} setSortBy={setSortBy} sortDir={sortDir} setSortDir={setSortDir} style={thStyle}/>
              <th style={{ ...thStyle, width:95 }}>Phone</th>
              {isAdmin && <th style={{ ...thStyle, width:88 }}>Project</th>}
              {isAdmin && <Th label="Assigned" col="assigned" width={120} sortBy={sortBy} setSortBy={setSortBy} sortDir={sortDir} setSortDir={setSortDir} style={thStyle}/>}
              {isAdmin && <Th label="Date"    col="date"     width={70}  sortBy={sortBy} setSortBy={setSortBy} sortDir={sortDir} setSortDir={setSortDir} style={thStyle}/>}
              <th style={{ ...thStyle, width:115 }}>Status</th>
              {isAdmin && <th style={{ ...thStyle, width:60 }}>Country</th>}
              {isAdmin && <th style={{ ...thStyle, width:82 }}>IP</th>}
              {isAdmin && <th style={{ ...thStyle, width:88 }}>URL</th>}
              <th style={{ ...thStyle, width:88 }}>Remarks</th>
              <th style={{ ...thStyle, width:76 }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={isAdmin ? 13 : 10} style={{ textAlign:'center', padding:32 }}>
                <div className="spinner" style={{ margin:'0 auto' }} />
              </td></tr>
            ) : leads.length === 0 ? (
              <tr><td colSpan={isAdmin ? 13 : 10}>
                <div className="empty-state" style={{ padding:32 }}>
                  <FileText size={32} /><h3>No leads found</h3>
                  <button className="btn btn-secondary btn-sm" onClick={resetFilters}>Clear Filters</button>
                </div>
              </td></tr>
            ) : leads.map(lead => (
              <tr key={lead.id} className={lead.is_duplicate ? 'is-duplicate' : ''}>
                {isAdmin && (
                  <td style={{ padding:'6px 4px', textAlign:'center' }}>
                    <button style={{ background:'none', border:'none', cursor:'pointer', padding:0,
                      color: selected.includes(lead.id) ? 'var(--primary)' : 'var(--text-muted)' }}
                      onClick={() => toggleSelect(lead.id)}>
                      {selected.includes(lead.id) ? <CheckSquare size={14}/> : <Square size={14}/>}
                    </button>
                  </td>
                )}
                <td style={{ ...tdStyle, color:'var(--text-muted)', fontSize:'0.7rem' }}>#{lead.id}</td>
                <td style={{ ...tdStyle }} title={lead.name || ''}><strong style={{ fontSize:'0.76rem' }}>{fmt(lead.name)}</strong></td>
                <td style={{ ...tdStyle }}>
                  {lead.phone
                    ? <a href={`tel:${lead.phone}`} style={{ color:'var(--primary)', fontWeight:600, fontSize:'0.76rem', textDecoration:'none' }}>{lead.phone}</a>
                    : '—'}
                </td>
                {isAdmin && (
                  <td style={{ ...tdStyle, fontSize:'0.7rem' }} title={lead.project || ''}>{fmt(lead.project)}</td>
                )}
                {isAdmin && (
                  <td style={{ padding:'4px 6px' }}>
                    <select style={cs} value={lead.assigned_to || ''} onChange={e => quickAssign(lead.id, e.target.value)}>
                      <option value="">Unassigned</option>
                      {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                  </td>
                )}
                {isAdmin && <td style={{ ...tdStyle, fontSize:'0.7rem' }}>{fmtDate(lead.created_at)}</td>}
                <td style={{ padding:'4px 6px' }}>
                  <select style={cs} value={lead.status || 'New'} onChange={e => quickStatus(lead.id, e.target.value)}>
                    {STATUSES.map(s => <option key={s}>{s}</option>)}
                  </select>
                </td>
                {isAdmin && <td style={{ ...tdStyle, fontSize:'0.7rem' }} title={lead.country || ''}>{fmt(lead.country)}</td>}
                {isAdmin && <td style={{ ...tdStyle, fontSize:'0.68rem', color:'var(--text-muted)' }} title={lead.ip_address || ''}>{fmt(lead.ip_address)}</td>}
                {isAdmin && (
                  <td style={{ ...tdStyle, fontSize:'0.7rem' }}>
                    {lead.refer_url
                      ? <a href={lead.refer_url} target="_blank" rel="noreferrer" style={{ color:'var(--primary)', fontSize:'0.7rem' }} title={lead.refer_url}>
                          {lead.refer_url.replace(/^https?:\/\//, '').slice(0, 22)}…
                        </a>
                      : '—'}
                  </td>
                )}
                <td style={{ ...tdStyle }} title={lead.remark || ''}>{fmt(lead.remark)}</td>
                <td style={{ padding:'4px 6px' }}>
                  <div style={{ display:'flex', gap:3 }}>
                    <button className="btn btn-secondary btn-sm" style={{ padding:'3px 5px' }} title="Timeline" onClick={() => openTimeline(lead)}><Eye size={11}/></button>
                    {!showDeleted && <button className="btn btn-secondary btn-sm" style={{ padding:'3px 5px' }} title="Edit" onClick={() => { setFeedbackModal(lead); setFeedbackForm({ status: lead.status||'New', remark: lead.remark||'' }) }}>✏️</button>}
                    {isAdmin && !showDeleted && <button className="btn btn-danger btn-sm" style={{ padding:'3px 5px' }} title="Trash" onClick={() => setDeleteConfirm({ mode:'single', ids:[lead.id] })}><Trash2 size={11}/></button>}
                    {isAdmin && showDeleted  && <button className="btn btn-danger btn-sm" style={{ padding:'3px 5px' }} title="Purge" onClick={() => setDeleteConfirm({ mode:'purge', ids:[lead.id] })}><Trash2 size={11}/></button>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* ── PAGINATION ── */}
      <div className="pagination" style={{ marginTop:12, gap:4 }}>
        <button className="page-btn" disabled={page === 1} onClick={() => setPage(p => p-1)}><ChevronLeft size={14}/></button>
        {Array.from({ length: Math.min(totalPages, 7) }, (_, i) => {
          const p = totalPages <= 7 ? i+1 : page <= 4 ? i+1 : page+i-3
          if (p < 1 || p > totalPages) return null
          return <button key={p} className={`page-btn ${p === page ? 'active' : ''}`} style={{ fontSize:'0.78rem' }} onClick={() => setPage(p)}>{p}</button>
        })}
        <button className="page-btn" disabled={page >= totalPages || totalPages === 0} onClick={() => setPage(p => p+1)}><ChevronRight size={14}/></button>
        <span style={{ fontSize:'0.75rem', color:'var(--text-muted)', marginLeft:8 }}>
          {page}/{totalPages} · {total.toLocaleString()}
        </span>
      </div>

      {/* ══ MODAL: Upload Preview ══════════════════════════════════ */}
      {previewData && (
        <div className="modal-overlay">
          <div className="modal modal-lg">
            <div className="modal-header">
              <h3>📤 Upload Preview — {previewData.files_count > 1 ? `${previewData.files_count} files` : '1 file'} ({previewData.total_rows} rows)</h3>
              <button className="modal-close" onClick={() => setPreviewData(null)}><X size={18}/></button>
            </div>
            <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:14, marginBottom:14 }}>
              <div className="form-group" style={{ margin:0 }}>
                <label className="form-label">Project Name <span style={{ color:'var(--danger)' }}>*</span></label>
                <input className="form-input" list="proj-list-ul" placeholder="Type or select…" value={projName} onChange={e => setProjName(e.target.value)} />
                <datalist id="proj-list-ul">
                  {(previewData.hidden_values || []).map(v => <option key={v} value={v}/>)}
                  {projects.map(p => <option key={p.id} value={p.name}/>)}
                </datalist>
                <p className="form-hint">Auto-detected from Hidden Field. Override if needed.</p>
              </div>
              <div className="form-group" style={{ margin:0 }}>
                <label className="form-label">Refer URL <span style={{ color:'var(--text-muted)' }}>(optional)</span></label>
                <input className="form-input"
                  placeholder={previewData.refer_detected ? 'Auto-detected in file' : 'Type URL or leave blank'}
                  value={referUrl} onChange={e => setReferUrl(e.target.value)} />
                <p className="form-hint">{previewData.refer_detected ? 'File has URL column. Manual entry overrides all.' : 'No URL found in file.'}</p>
              </div>
            </div>
            <div style={{ overflowX:'auto', maxHeight:240, border:'1px solid var(--border)', borderRadius:'var(--radius-md)' }}>
              <table>
                <thead><tr><th>#</th><th>Phone</th><th>Name</th><th>Hidden Field</th><th>Country</th><th>Device</th><th>IP</th><th>URL</th></tr></thead>
                <tbody>
                  {(previewData.preview || []).map((row, i) => (
                    <tr key={i}>
                      <td>{i+1}</td><td>{row.phone||'—'}</td><td>{row.name||'—'}</td>
                      <td>{row.hidden_field||row.project||'—'}</td>
                      <td>{row.country||'—'}</td><td>{row.device||'—'}</td>
                      <td style={{ fontSize:'0.72rem' }}>{row.ip_address||'—'}</td>
                      <td className="truncate" style={{ maxWidth:100 }}>{row.refer_url||'—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {previewData.total_rows > 20 && <p style={{ fontSize:'0.76rem', color:'var(--text-muted)', marginTop:6 }}>Showing 20 of {previewData.total_rows}. All imported on confirm.</p>}
            <div className="modal-footer">
              <button className="btn btn-secondary" onClick={() => setPreviewData(null)}>Cancel</button>
              <button className="btn btn-primary" onClick={handleConfirmUpload} disabled={confirming}>
                {confirming ? 'Saving…' : `✅ Confirm (${previewData.total_rows} rows)`}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ══ MODAL: Timeline ══════════════════════════════════════ */}
      {timelineModal && (
        <div className="modal-overlay">
          <div className="modal modal-lg">
            <div className="modal-header">
              <h3>📋 Timeline — {timelineModal.phone}</h3>
              <button className="modal-close" onClick={() => setTimelineModal(null)}><X size={18}/></button>
            </div>
            <div style={{ marginBottom:10, fontSize:'0.82rem' }}>
              <strong>{timelineModal.name}</strong>
              {timelineModal.project   && <span style={{ marginLeft:8, color:'var(--text-muted)' }}>· {timelineModal.project}</span>}
              {timelineModal.country   && <span style={{ marginLeft:8, color:'var(--text-muted)' }}>· 🌐 {timelineModal.country}</span>}
              {timelineModal.device    && <span style={{ marginLeft:8, color:'var(--text-muted)' }}>· 📱 {timelineModal.device}</span>}
              {timelineModal.ip_address && <div style={{ fontSize:'0.72rem', color:'var(--text-muted)', marginTop:2 }}>IP: {timelineModal.ip_address}</div>}
              {timelineModal.refer_url  && <div style={{ fontSize:'0.72rem', color:'var(--text-muted)' }}>🔗 {timelineModal.refer_url}</div>}
            </div>
            {timeline.length === 0
              ? <div className="empty-state" style={{ padding:20 }}><p>No timeline events yet.</p></div>
              : <div className="timeline">
                  {timeline.map(ev => (
                    <div key={ev.id} className="timeline-item">
                      <div className="timeline-dot"/>
                      <div className="timeline-content">
                        <div className="timeline-event">{ev.event_type}</div>
                        <div className="timeline-desc">{ev.description}</div>
                        <div className="timeline-meta">{ev.actor_name} · {new Date(ev.created_at).toLocaleString('en-IN')}</div>
                      </div>
                    </div>
                  ))}
                </div>}
            <div className="modal-footer">
              <button className="btn btn-secondary" onClick={() => setTimelineModal(null)}>Close</button>
            </div>
          </div>
        </div>
      )}

      {/* ══ MODAL: Feedback ══════════════════════════════════════ */}
      {feedbackModal && (
        <div className="modal-overlay">
          <div className="modal">
            <div className="modal-header">
              <h3>✏️ Update — {feedbackModal.phone}</h3>
              <button className="modal-close" onClick={() => setFeedbackModal(null)}><X size={18}/></button>
            </div>
            <div className="form-group">
              <label className="form-label">Status</label>
              <select className="form-select" value={feedbackForm.status}
                onChange={e => setFeedbackForm(f => ({ ...f, status: e.target.value }))}>
                {STATUSES.map(s => <option key={s}>{s}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label className="form-label">Remark</label>
              <textarea className="form-textarea" rows={3} value={feedbackForm.remark}
                onChange={e => setFeedbackForm(f => ({ ...f, remark: e.target.value }))}/>
            </div>
            <div className="modal-footer">
              <button className="btn btn-secondary" onClick={() => setFeedbackModal(null)}>Cancel</button>
              <button className="btn btn-primary" onClick={submitFeedback}>Save</button>
            </div>
          </div>
        </div>
      )}

      {/* ══ MODAL: Delete Confirm ════════════════════════════════ */}
      {deleteConfirm && (
        <div className="modal-overlay">
          <div className="modal">
            <div className="modal-header">
              <h3 style={{ color:'var(--danger)' }}><AlertTriangle size={16} style={{ marginRight:6 }}/>Confirm</h3>
              <button className="modal-close" onClick={() => setDeleteConfirm(null)}><X size={18}/></button>
            </div>
            <p style={{ color:'var(--text-secondary)', marginBottom:20 }}>
              {deleteConfirm.mode === 'purge_all' ? 'Permanently delete ALL trash leads? Cannot be undone.' :
               deleteConfirm.mode === 'purge'     ? `Permanently delete ${deleteConfirm.ids?.length || 1} lead(s)?` :
               deleteConfirm.mode === 'project'   ? `Move all leads in "${deleteConfirm.project}" to trash?` :
               deleteConfirm.mode === 'bulk'      ? `Move ${deleteConfirm.ids?.length} leads to trash?` :
               'Move this lead to trash?'}
            </p>
            <div className="modal-footer">
              <button className="btn btn-secondary" onClick={() => setDeleteConfirm(null)}>Cancel</button>
              <button className="btn btn-danger" onClick={confirmDelete}>
                {['purge','purge_all'].includes(deleteConfirm.mode) ? '🗑 Delete Forever' : 'Move to Trash'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
