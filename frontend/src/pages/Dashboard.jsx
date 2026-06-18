import { useEffect, useState, useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { getStats, getAllLocations } from '../api/axios.js'
import {
  Users, Upload, GitBranch, TrendingUp, AlertTriangle, CheckCircle,
  RefreshCw, MapPin, X, BarChart2, PieChart as PieIcon, Calendar,
  Smartphone, Globe, Repeat, UserCheck, UserX, Activity
} from 'lucide-react'
import {
  BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell,
  PieChart, Pie, Legend
} from 'recharts'
import toast from 'react-hot-toast'

const STATUS_COLORS = {
  'New':'#06b6d4','Assigned':'#7c3aed','Called':'#a855f7',
  'Interested':'#10b981','Follow Up':'#f59e0b','Site Visit':'#8b5cf6',
  'Booked':'#34d399','Not Interested':'#ef4444','Wrong Number':'#6b7280'
}
const PIE_COLORS = ['#7c3aed','#06b6d4','#10b981','#f59e0b','#ef4444','#a855f7','#8b5cf6','#34d399','#6b7280','#f97316','#ec4899','#14b8a6']

/* ── Date helpers ─────────────────────────────────────────── */
const fmt = (d) => d.toISOString().split('T')[0]
function computeDateRange(range) {
  const today = new Date()
  if (range === 'today')     return { from: fmt(today), to: fmt(today) }
  if (range === 'yesterday') { const y = new Date(today); y.setDate(y.getDate()-1); return { from: fmt(y), to: fmt(y) } }
  if (range === '7d')        { const d = new Date(today); d.setDate(d.getDate()-6); return { from: fmt(d), to: fmt(today) } }
  if (range === '30d')       { const d = new Date(today); d.setDate(d.getDate()-29); return { from: fmt(d), to: fmt(today) } }
  return { from: '', to: '' }
}

/* ── Reusable Analytics Chart Widget ─────────────────────── */
function ChartWidget({ title, Icon, data, nameKey, countKey, chartType, setChartType, onItemClick, emptyMsg, headerExtra }) {
  const safeData = (data || []).filter(r => r[nameKey] && r[countKey] > 0)
  const total    = safeData.reduce((s, r) => s + Number(r[countKey] || 0), 0)

  const tooltipStyle = {
    contentStyle: { background:'var(--bg-card)', border:'1px solid var(--border)', borderRadius:8, color:'var(--text-primary)', fontSize:12 },
    cursor: { fill:'rgba(124,58,237,0.08)' }
  }

  return (
    <div className="card">
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:14 }}>
        <div className="section-title" style={{ margin:0 }}>
          <Icon size={17} color="var(--accent)"/> {title}
        </div>
        <div style={{ display:'flex', gap:10, alignItems: 'center' }}>
          {headerExtra}
          <div style={{ display:'flex', gap:3 }}>
            <button
              onClick={() => setChartType('bar')}
              title="Bar chart"
              style={{ background: chartType==='bar' ? 'var(--primary)' : 'var(--bg-hover)',
                border:'none', borderRadius:6, padding:'3px 8px', cursor:'pointer',
                color: chartType==='bar' ? '#fff' : 'var(--text-muted)', fontSize:'0.72rem', fontWeight:600 }}
            ><BarChart2 size={12} style={{ verticalAlign:'middle', marginRight:2 }}/>Bar</button>
            <button
              onClick={() => setChartType('pie')}
              title="Pie chart"
              style={{ background: chartType==='pie' ? 'var(--primary)' : 'var(--bg-hover)',
                border:'none', borderRadius:6, padding:'3px 8px', cursor:'pointer',
                color: chartType==='pie' ? '#fff' : 'var(--text-muted)', fontSize:'0.72rem', fontWeight:600 }}
            ><PieIcon size={12} style={{ verticalAlign:'middle', marginRight:2 }}/>Pie</button>
          </div>
        </div>
      </div>

      {safeData.length === 0
        ? <div className="empty-state" style={{ padding:24 }}><p>{emptyMsg || 'No data available'}</p></div>
        : chartType === 'bar'
          ? (
            <ResponsiveContainer width="100%" height={220}>
              <BarChart data={safeData} barSize={22}
                onClick={e => e?.activePayload?.[0] && onItemClick(e.activePayload[0].payload[nameKey])}>
                <XAxis dataKey={nameKey} tick={{fill:'var(--text-muted)',fontSize:10}} tickLine={false} axisLine={false}
                  angle={-35} textAnchor="end" height={60}/>
                <YAxis tick={{fill:'var(--text-muted)',fontSize:10}} tickLine={false} axisLine={false}/>
                <Tooltip {...tooltipStyle}/>
                <Bar dataKey={countKey} radius={[5,5,0,0]} style={{ cursor:'pointer' }}>
                  {safeData.map((_, i) => <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]}/>)}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <ResponsiveContainer width="100%" height={220}>
              <PieChart>
                <Pie data={safeData} dataKey={countKey} nameKey={nameKey}
                  cx="50%" cy="50%" outerRadius={70}
                  label={e => e[nameKey] ? `${e[nameKey]} (${((e[countKey]/total)*100).toFixed(0)}%)` : ''}
                  labelLine onClick={e => onItemClick(e[nameKey])}
                  style={{ cursor:'pointer' }}>
                  {safeData.map((_, i) => <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]}/>)}
                </Pie>
                <Tooltip contentStyle={{ background:'var(--bg-card)', border:'1px solid var(--border)', borderRadius:8, color:'var(--text-primary)', fontSize:12 }}
                  formatter={val => [val + ' leads']}/>
                <Legend wrapperStyle={{ fontSize:'0.72rem', color:'var(--text-muted)' }}/>
              </PieChart>
            </ResponsiveContainer>
          )
      }
    </div>
  )
}

/* ── Main Dashboard ───────────────────────────────────────── */
export default function Dashboard() {
  const navigate = useNavigate()
  const [stats, setStats]                   = useState(null)
  const [loading, setLoading]               = useState(true)
  const [allLocations, setAllLocations]     = useState([])
  const [selectedLocation, setSelectedLocation] = useState('')

  // Date range filter
  const [dateRange,   setDateRange]   = useState('all')
  const [customFrom,  setCustomFrom]  = useState('')
  const [customTo,    setCustomTo]    = useState('')

  // Chart type toggles for 5 analytics widgets
  const [projChart,    setProjChart]    = useState('bar')
  const [nriChart,     setNriChart]     = useState('pie')
  const [countryChart, setCountryChart] = useState('bar')
  const [deviceChart,  setDeviceChart]  = useState('pie')
  
  const [excludeIndia, setExcludeIndia] = useState(false)

  const user = useMemo(() => {
    try {
      return JSON.parse(localStorage.getItem('lead8x_user') || '{}');
    } catch {
      return {};
    }
  }, []);
  // Compute effective date range
  const { from: effectiveDateFrom, to: effectiveDateTo } = useMemo(() => {
    if (dateRange === 'custom') return { from: customFrom, to: customTo }
    return computeDateRange(dateRange)
  }, [dateRange, customFrom, customTo])

  // Filter country data
  const countryData = useMemo(() => {
    const data = stats?.country_breakdown || [];
    if (excludeIndia) return data.filter(d => d.country?.toLowerCase() !== 'india');
    return data;
  }, [stats?.country_breakdown, excludeIndia]);

  /* ── Load all distinct location names for filter ── */
  useEffect(() => {
    getAllLocations()
      .then(r => setAllLocations(r.data?.data?.locations || []))
      .catch(() => setAllLocations([]))
  }, [])

  /* ── Load stats (re-runs when location or date range changes) ── */
  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (selectedLocation)   params.location  = selectedLocation
      if (effectiveDateFrom)  params.date_from = effectiveDateFrom
      if (effectiveDateTo)    params.date_to   = effectiveDateTo
      const res = await getStats(Object.keys(params).length ? params : undefined)
      setStats(res.data?.data || null)
    } catch { toast.error('Failed to load dashboard stats.') }
    finally { setLoading(false) }
  }, [selectedLocation, effectiveDateFrom, effectiveDateTo])

  useEffect(() => { load() }, [load])

  /* ── Drill-down navigation helper ── */
  const drillTo = (filter) => navigate('/leads', { state: { drilldown: filter } })

  /* ── Date range pill helper ── */
  const rangePills = [
    { key:'all',       label:'All Time' },
    { key:'today',     label:'Today' },
    { key:'yesterday', label:'Yesterday' },
    { key:'7d',        label:'Last 7 Days' },
    { key:'30d',       label:'Last 30 Days' },
    { key:'custom',    label:'Custom' },
  ]

  /* ── Loading state (preserved exactly) ── */
  if (loading) return (
    <div>
      <div className="topbar"><h1>Dashboard</h1></div>
      <div className="loading-overlay"><div className="spinner"/><span>Loading stats…</span></div>
    </div>
  )

  const ov = stats?.overview || {}

  /* ── KPI card data ─────────────────────────────────────────── */
  const kpiCards = [
    {
      label:'Total Leads', value: ov.total_leads?.toLocaleString(),
      icon: Upload, color:'#7c3aed',
      sub: `${ov.duplicate_leads || 0} duplicates`,
      onClick: () => drillTo({}),
      tip: 'Click to view all leads'
    },
    {
      label:'Assigned', value: ov.assigned_leads?.toLocaleString(),
      icon: UserCheck, color:'#10b981',
      sub: 'Leads with assignee',
      onClick: () => drillTo({ assigned_only: true }),
      tip: 'Click to view assigned leads'
    },
    {
      label:'Unassigned', value: ov.unassigned_leads?.toLocaleString(),
      icon: UserX, color:'#f59e0b',
      sub: 'No assignee yet',
      onClick: () => drillTo({ unassigned_only: true }),
      tip: 'Click to view unassigned leads'
    },
    {
      label:'Duplicates', value: ov.duplicate_leads?.toLocaleString(),
      icon: AlertTriangle, color:'#ef4444',
      sub: 'Same phone, new batch',
      onClick: () => drillTo({ is_duplicate: 1 }),
      tip: 'Click to view duplicate leads'
    },
    {
      label:'Active Users', value: ov.total_users?.toLocaleString(),
      icon: Users, color:'#06b6d4',
      sub: 'Licensed team members',
      onClick: null,
      tip: null
    }
  ]

  return (
    <div>
      {/* ── HEADER (preserved exactly, with date range added) ── */}
      <div className="topbar">
        <h1>Dashboard</h1>
        <div className="topbar-actions">
          <span style={{ color:'var(--text-muted)', fontSize:'0.85rem' }}>
            Welcome, <strong>{user.name}</strong> · {user.role}
          </span>

          {/* Location filter dropdown (preserved exactly) */}
          <div style={{ display:'flex', alignItems:'center', gap:6 }}>
            <MapPin size={14} color="var(--accent)" />
            <select
              className="form-select"
              style={{ fontSize:'0.82rem', padding:'4px 8px', minWidth:150,
                borderColor: selectedLocation ? 'var(--primary)' : undefined,
                fontWeight: selectedLocation ? 600 : undefined }}
              value={selectedLocation}
              onChange={e => setSelectedLocation(e.target.value)}
            >
              <option value="">All Locations</option>
              {allLocations.map(loc => (
                <option key={loc} value={loc}>{loc}</option>
              ))}
            </select>
            {selectedLocation && (
              <button
                onClick={() => setSelectedLocation('')}
                style={{ background:'none', border:'none', cursor:'pointer', color:'var(--text-muted)', padding:2, display:'flex' }}
                title="Clear location filter"
              >
                <X size={14} />
              </button>
            )}
          </div>

          <button className="btn btn-secondary btn-sm" onClick={load}><RefreshCw size={14}/> Refresh</button>
        </div>
      </div>

      {/* ── DATE RANGE FILTER BAR (NEW) ──────────────────────── */}
      <div style={{ padding:'10px 20px', borderBottom:'1px solid var(--border)',
        background:'var(--bg-card)', display:'flex', alignItems:'center', gap:8, flexWrap:'wrap' }}>
        <Calendar size={14} color="var(--text-muted)" />
        <span style={{ fontSize:'0.8rem', color:'var(--text-muted)', fontWeight:500, marginRight:4 }}>Date Range:</span>
        {rangePills.map(({ key, label }) => (
          <button key={key}
            onClick={() => setDateRange(key)}
            style={{
              fontSize:'0.78rem', padding:'3px 12px', borderRadius:20, border:'1px solid',
              cursor:'pointer', fontWeight: dateRange===key ? 700 : 400,
              borderColor: dateRange===key ? 'var(--primary)' : 'var(--border)',
              background: dateRange===key ? 'var(--primary)' : 'transparent',
              color: dateRange===key ? '#fff' : 'var(--text-muted)',
              transition:'all 0.15s'
            }}
          >{label}</button>
        ))}
        {dateRange === 'custom' && (
          <div style={{ display:'flex', alignItems:'center', gap:6, marginLeft:4 }}>
            <input type="date" className="form-select"
              style={{ fontSize:'0.8rem', padding:'3px 6px', width:140 }}
              value={customFrom} onChange={e => setCustomFrom(e.target.value)}/>
            <span style={{ fontSize:'0.8rem', color:'var(--text-muted)' }}>to</span>
            <input type="date" className="form-select"
              style={{ fontSize:'0.8rem', padding:'3px 6px', width:140 }}
              value={customTo} onChange={e => setCustomTo(e.target.value)}/>
          </div>
        )}
        {dateRange !== 'all' && (effectiveDateFrom || effectiveDateTo) && (
          <span style={{ fontSize:'0.75rem', color:'var(--text-muted)', marginLeft:4 }}>
            {effectiveDateFrom} → {effectiveDateTo || 'today'}
          </span>
        )}
      </div>

      {/* Active location indicator (preserved exactly) */}
      {selectedLocation && (
        <div style={{ padding:'6px 20px', background:'var(--primary-light)', borderBottom:'1px solid var(--border)',
          display:'flex', alignItems:'center', gap:8, fontSize:'0.8rem', color:'var(--primary)', fontWeight:600 }}>
          <MapPin size={13} />
          Showing data for: {selectedLocation}
          <button onClick={() => setSelectedLocation('')}
            style={{ background:'none', border:'none', cursor:'pointer', color:'var(--primary)', padding:0, marginLeft:4, display:'flex' }}>
            <X size={12} />
          </button>
        </div>
      )}

      <div className="page">

        {/* ── KPI CARDS (7 cards — 4 existing preserved + 3 new) ── */}
        <div style={{ display:'grid', gridTemplateColumns:'repeat(auto-fill, minmax(170px,1fr))', gap:14, marginBottom:24 }}>
          {kpiCards.map((item, i) => {
            const Icon = item.icon
            return (
              <div
                key={i}
                className="stat-card"
                onClick={item.onClick || undefined}
                title={item.tip || ''}
                style={{ cursor: item.onClick ? 'pointer' : 'default',
                  transition:'transform 0.15s, box-shadow 0.15s',
                  ...(item.onClick ? { ':hover':{ transform:'translateY(-2px)' } } : {}) }}
                onMouseEnter={e => { if (item.onClick) e.currentTarget.style.transform='translateY(-2px)' }}
                onMouseLeave={e => { if (item.onClick) e.currentTarget.style.transform='' }}
              >
                <div className="stat-icon" style={{ background: item.color + '22' }}>
                  <Icon size={22} color={item.color}/>
                </div>
                <div className="stat-content">
                  <div className="stat-value" style={{ color: 'var(--text-primary)' }}>{item.value ?? '–'}</div>
                  <div className="stat-label">{item.label}</div>
                  <div className="stat-sub">{item.sub}</div>
                </div>
              </div>
            )
          })}
        </div>

        {/* ── STATUS BREAKDOWN + LOCATION PIE (preserved EXACTLY) ── */}
        <div className="grid grid-2 mb-6">
          {/* Status Breakdown Chart — preserved exactly */}
          <div className="card">
            <div className="section-title"><TrendingUp size={18} color="var(--accent)"/> Status Breakdown
              {selectedLocation && <span style={{ fontSize:'0.72rem', color:'var(--text-muted)', fontWeight:400, marginLeft:6 }}>({selectedLocation})</span>}
            </div>
            <ResponsiveContainer width="100%" height={240}>
              <BarChart data={stats?.status_breakdown || []} barSize={30}
                onClick={e => e?.activePayload?.[0] && drillTo({ status: e.activePayload[0].payload.status })}>
                <XAxis dataKey="status" tick={{fill:'var(--text-muted)',fontSize:11}} tickLine={false} axisLine={false}/>
                <YAxis tick={{fill:'var(--text-muted)',fontSize:11}} tickLine={false} axisLine={false}/>
                <Tooltip
                  contentStyle={{ background:'var(--bg-card)', border:'1px solid var(--border)', borderRadius:8, color:'var(--text-primary)', fontSize:12 }}
                  cursor={{ fill:'rgba(124,58,237,0.08)' }}
                />
                <Bar dataKey="count" radius={[6,6,0,0]} style={{ cursor:'pointer' }}>
                  {(stats?.status_breakdown || []).map((entry, i) => (
                    <Cell key={i} fill={STATUS_COLORS[entry.status] || '#7c3aed'}/>
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>

          {/* Location Distribution Pie Chart — hidden if location is selected */}
          {!selectedLocation && (
            <div className="card">
              <div className="section-title"><MapPin size={18} color="var(--accent)"/> Location Distribution
                <span style={{ fontSize:'0.72rem', color:'var(--text-muted)', fontWeight:400, marginLeft:6 }}>(global)</span>
              </div>
              {(stats?.location_breakdown || []).length === 0
                ? <div className="empty-state"><p>No location data yet. Set locations in Project Manager.</p></div>
                : <ResponsiveContainer width="100%" height={240}>
                    <PieChart>
                      <Pie
                        data={stats.location_breakdown}
                        dataKey="count"
                        nameKey="location"
                        cx="50%" cy="50%"
                        outerRadius={75}
                        label={(entry) => entry.location ? `${entry.location} (${((entry.count / stats.location_breakdown.reduce((s,r)=>s+Number(r.count),0))*100).toFixed(0)}%)` : ''}
                        labelLine
                        onClick={e => drillTo({ location: e.location })}
                        style={{ cursor:'pointer' }}
                      >
                        {stats.location_breakdown.map((_, i) => (
                          <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                        ))}
                      </Pie>
                      <Tooltip
                        contentStyle={{ background:'var(--bg-card)', border:'1px solid var(--border)', borderRadius:8, color:'var(--text-primary)', fontSize:12 }}
                        formatter={(val) => [val + ' leads']}
                      />
                      <Legend wrapperStyle={{ fontSize:'0.73rem', color:'var(--text-muted)' }} />
                    </PieChart>
                  </ResponsiveContainer>
              }
            </div>
          )}

          {/* Recent Batches — preserved exactly */}
          <div className="card">
            <div className="section-title"><Upload size={18} color="var(--accent)"/> Recent Uploads
              {selectedLocation && <span style={{ fontSize:'0.72rem', color:'var(--text-muted)', fontWeight:400, marginLeft:6 }}>({selectedLocation})</span>}
            </div>
            {(stats?.recent_batches || []).length === 0
              ? <div className="empty-state"><p>No batches yet.</p></div>
              : <div className="table-wrapper">
                  <table>
                    <thead><tr><th>Batch ID</th><th>Source</th><th>Total</th><th>Dups</th><th>Date</th></tr></thead>
                    <tbody>
                      {stats.recent_batches.map((b,i) => (
                        <tr key={i}>
                          <td style={{fontFamily:'monospace',fontSize:'0.78rem'}}>{b.batch_id}</td>
                          <td>{b.source || '–'}</td>
                          <td><strong>{b.total}</strong></td>
                          <td style={{color:'var(--warning)'}}>{b.duplicates}</td>
                          <td className="text-muted text-xs">{new Date(b.uploaded_at).toLocaleDateString('en-IN')}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
            }
          </div>
        </div>

        {/* ── NEW: 5 ANALYTICS CHART WIDGETS ───────────────────── */}
        <div style={{ marginBottom:24 }}>
          <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom:16,
            paddingBottom:10, borderBottom:'1px solid var(--border)' }}>
            <TrendingUp size={16} color="var(--accent)"/>
            <h2 style={{ margin:0, fontSize:'1rem', fontWeight:700, color:'var(--text-primary)' }}>
              Lead Analytics
            </h2>
            {(effectiveDateFrom || selectedLocation) && (
              <span style={{ fontSize:'0.75rem', color:'var(--text-muted)', fontWeight:400 }}>
                {selectedLocation ? `· ${selectedLocation}` : ''} {effectiveDateFrom ? `· ${effectiveDateFrom} to ${effectiveDateTo || 'today'}` : ''}
              </span>
            )}
          </div>

          <div className="grid grid-2" style={{ gap:16 }}>
            <ChartWidget
              title="Project Distribution" Icon={GitBranch}
              data={stats?.project_breakdown || []} nameKey="project" countKey="count"
              chartType={projChart} setChartType={setProjChart}
              onItemClick={name => drillTo({ project: name })}
              emptyMsg="No project data available"
            />
            <ChartWidget
              title="NRI Distribution" Icon={Globe}
              data={stats?.nri_breakdown || []} nameKey="label" countKey="count"
              chartType={nriChart} setChartType={setNriChart}
              onItemClick={name => drillTo({ is_nri: name === 'NRI' ? 1 : 0 })}
              emptyMsg="No NRI data available"
            />
            <ChartWidget
              title="Country Distribution" Icon={Globe}
              data={countryData} nameKey="country" countKey="count"
              chartType={countryChart} setChartType={setCountryChart}
              onItemClick={name => drillTo({ country: name })}
              emptyMsg="No country data available"
              headerExtra={
                <label style={{ fontSize: '0.75rem', display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer', color: 'var(--text-muted)' }}>
                  <input type="checkbox" checked={excludeIndia} onChange={e => setExcludeIndia(e.target.checked)} />
                  Exclude India
                </label>
              }
            />
            <ChartWidget
              title="Device Distribution" Icon={Smartphone}
              data={stats?.device_breakdown || []} nameKey="device" countKey="count"
              chartType={deviceChart} setChartType={setDeviceChart}
              onItemClick={name => drillTo({ device: name })}
              emptyMsg="No device data available"
            />
          </div>
        </div>

        {/* ── TEAM PERFORMANCE (preserved EXACTLY) ── */}
        <div className="card">
          <div className="section-title"><CheckCircle size={18} color="var(--accent)"/> Team Performance
            {selectedLocation && <span style={{ fontSize:'0.72rem', color:'var(--text-muted)', fontWeight:400, marginLeft:6 }}>({selectedLocation})</span>}
          </div>
          <div className="table-wrapper">
            <table>
              <thead>
                <tr><th>#</th><th>Name</th><th>Role</th><th>Total Leads</th><th>Interested</th><th>Booked</th><th>Conversion</th></tr>
              </thead>
              <tbody>
                {(stats?.user_stats || []).map((u, i) => {
                  const conv = u.total_leads > 0 ? ((u.booked / u.total_leads) * 100).toFixed(1) : 0
                  return (
                    <tr key={u.id}>
                      <td className="text-muted text-xs">{i+1}</td>
                      <td><strong>{u.name}</strong></td>
                      <td><span className={`role-badge role-${u.role.replace(' ','')}`}>{u.role}</span></td>
                      <td>{u.total_leads}</td>
                      <td style={{color:'var(--success)'}}>{u.interested}</td>
                      <td style={{color:'var(--accent)',fontWeight:700}}>{u.booked}</td>
                      <td>
                        <div style={{display:'flex',alignItems:'center',gap:8}}>
                          <div className="progress-bar" style={{width:80}}>
                            <div className="progress-fill" style={{width:`${conv}%`}}/>
                          </div>
                          <span className="text-xs text-muted">{conv}%</span>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  )
}
