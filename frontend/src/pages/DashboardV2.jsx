import React, { useEffect, useState, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios.js';
import {
  Users, Upload, AlertTriangle, Plus, Activity,
  RefreshCw, MapPin, X, BarChart2, CheckCircle,
  Smartphone, UserPlus, FileText, Download,
  TrendingUp, Clock, ShieldAlert, Zap
} from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell, PieChart, Pie, Legend } from 'recharts';
import toast from 'react-hot-toast';

const API_V2_URL = '/dashboard_v2.php';
const PIE_COLORS = ['#7c3aed','#06b6d4','#10b981','#f59e0b','#ef4444','#a855f7','#8b5cf6','#34d399','#6b7280'];

export default function DashboardV2() {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [location, setLocation] = useState('');
  const [allLocations, setAllLocations] = useState([]);
  const [dateRange, setDateRange] = useState('all'); // Default to Overall (All Time)


  useEffect(() => {
    api.get('/projects/locations.php', { params: { all_locations: 1 } })
      .then(r => setAllLocations(r.data?.data?.locations || []))
      .catch(() => setAllLocations([]));
  }, []);

  const currentSignalRef = useRef(null);   // tracks the active request signal
  const manualRefreshAbortRef = useRef(null); // AbortController for Refresh button

  const loadData = useCallback((signal) => {
    currentSignalRef.current = signal;
    setLoading(true);
    setError(null);
    api.get(API_V2_URL, { params: { location, dateRange }, signal })
      .then(res => {
        setData(res.data?.data ?? null);
      })
      .catch(err => {
        if (err?.name === 'CanceledError' || err?.name === 'AbortError') return;
        setError('Data flow interrupted. Check your connection.');
        toast.error('Failed to load Dashboard stats');
      })
      .finally(() => {
        // Only clear loading if this signal is still the active one and was not aborted
        if (signal === currentSignalRef.current && !signal?.aborted) {
          setLoading(false);
        }
      });
  }, [location]);

  /** Abort any in-flight manual refresh then start a fresh request. */
  const handleManualRefresh = useCallback(() => {
    if (manualRefreshAbortRef.current) {
      manualRefreshAbortRef.current.abort();
    }
    const controller = new AbortController();
    manualRefreshAbortRef.current = controller;
    loadData(controller.signal);
  }, [loadData]);

  useEffect(() => {
    const controller = new AbortController();
    loadData(controller.signal);
    return () => controller.abort(); 
  }, [loadData, dateRange, location]);

  if (loading && !data) return (
    <div>
      <div className="topbar"><h1>Command Center ⚡</h1></div>
      <div className="loading-overlay"><div className="spinner"/><span>Synchronizing Intelligence...</span></div>
    </div>
  );

  if (error && !data) return (
    <div className="analytics-page">
      <div className="topbar"><h1>Command Center ⚡</h1></div>
      <div className="page" style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', height: '60vh' }}>
        <AlertTriangle size={48} color="var(--danger)" style={{ marginBottom: 16 }} />
        <h2 style={{ marginBottom: 8 }}>Internal Data Sync Error</h2>
        <p style={{ color: 'var(--text-muted)', marginBottom: 20 }}>{error}</p>
        <button className="btn btn-primary" onClick={handleManualRefresh}><RefreshCw size={14} style={{ marginRight: 8 }}/> Retry Connection</button>
      </div>
    </div>
  );

  const stats = data || {};
  const kpis = stats.kpis || {};


  return (
    <div className="dashboard-v2">
      {/* HEADER */}
      <div className="topbar">
        <h1>Command Center ⚡</h1>
          <div style={{ display:'flex', alignItems:'center', gap:10 }}>
            {/* DATE FILTER DROPDOWN */}
            <div style={{ display:'flex', alignItems:'center', gap:6 }}>
              <Clock size={14} color="var(--primary)" />
              <select
                className="form-select"
                value={dateRange}
                onChange={e => setDateRange(e.target.value)}
                style={{ fontSize:'0.82rem', padding:'4px 8px', minWidth:120 }}
              >
                <option value="all">Overall (All Time)</option>
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="month">This Month</option>
                <option value="year">This Year</option>
              </select>
            </div>

            <div style={{ display:'flex', alignItems:'center', gap:6 }}>
              <MapPin size={14} color="var(--accent)" />
              <select
                className="form-select"
                value={location}
                onChange={e => setLocation(e.target.value)}
                style={{ fontSize:'0.82rem', padding:'4px 8px', minWidth:120 }}
              >
                <option value="">All Locations</option>
                {allLocations.map(loc => <option key={loc} value={loc}>{loc}</option>)}
              </select>
            </div>
          </div>
          <button className="btn btn-secondary btn-sm" onClick={handleManualRefresh} style={{ padding: '6px 12px' }}>
            {loading ? <RefreshCw size={14} className="spin"/> : <RefreshCw size={14}/>} Refresh
          </button>
        </div>

      <div className="page" style={{ paddingTop: 10 }}>

        {/* SMART ALERTS */}
        {stats.alerts?.length > 0 && (
          <div style={{ marginBottom: 20, display: 'flex', flexDirection: 'column', gap: 10 }}>
            {stats.alerts.map((alert, i) => (
              <div key={i} style={{ 
                background: alert.type === 'danger' ? '#fee2e2' : '#fef3c7', 
                color: alert.type === 'danger' ? '#991b1b' : '#92400e',
                padding: '10px 16px', borderRadius: 8, display: 'flex', alignItems: 'center', gap: 10, fontSize: '0.85rem', fontWeight: 600
              }}>
                <ShieldAlert size={18} />
                {alert.message}
              </div>
            ))}
          </div>
        )}

        {/* QUICK ACTIONS ENGINE */}
        <div style={{ display: 'flex', gap: 12, marginBottom: 20, flexWrap: 'wrap' }}>
          <button className="btn btn-primary" onClick={() => navigate('/leads')}><Upload size={16}/> Upload Leads</button>
          <button className="btn btn-secondary" onClick={() => navigate('/leads')}><UserPlus size={16}/> Bulk Assign</button>
          <button className="btn btn-secondary" onClick={() => navigate('/leads')}><Plus size={16}/> Add Manual Lead</button>
          <button className="btn btn-secondary" onClick={() => navigate('/leads')}><Download size={16}/> Export Segment</button>
        </div>

        {/* KPI SCORING SYSTEM */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 12 }}>
          <h2 style={{ fontSize: '1.1rem', margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <TrendingUp size={18} color="var(--primary)"/> Performance Metrics 
            <span style={{ fontSize: '0.75rem', fontWeight: 400, color: 'var(--text-muted)', marginLeft: 8 }}>
              {dateRange === 'all' ? `Showing ${stats.kpis?.total_overall || 0} Total leads` : `Filtered by ${dateRange}`}
            </span>
          </h2>
        </div>

        <div style={{ display:'grid', gridTemplateColumns:'repeat(auto-fit, minmax(160px, 1fr))', gap: 14, marginBottom: 24 }}>
          <div className="stat-card">
            <div className="stat-icon" style={{ background: '#7c3aed22' }}><Users size={22} color="#7c3aed"/></div>
            <div className="stat-content">
              <div className="stat-value">{stats.kpis?.total_leads || 0}</div>
              <div className="stat-label">Total Leads</div>
            </div>
          </div>
          <div className="stat-card">
            <div className="stat-icon" style={{ background: '#10b98122' }}><CheckCircle size={22} color="#10b981"/></div>
            <div className="stat-content">
              <div className="stat-value">{stats.kpis?.assigned_leads || 0}</div>
              <div className="stat-label">Assigned</div>
            </div>
          </div>
          <div className="stat-card">
             <div className="stat-icon" style={{ background: '#f59e0b22' }}><Clock size={22} color="#f59e0b"/></div>
            <div className="stat-content">
               <div className="stat-value">{stats.kpis?.unassigned_leads || 0}</div>
               <div className="stat-label">Unassigned</div>
            </div>
          </div>
          <div className="stat-card">
             <div className="stat-icon" style={{ background: '#06b6d422' }}><Zap size={22} color="#06b6d4"/></div>
            <div className="stat-content">
               <div className="stat-value">{stats.kpis?.fresh_leads || 0}</div>
               <div className="stat-label">Fresh (New Status)</div>
            </div>
          </div>
          <div className="stat-card">
             <div className="stat-icon" style={{ background: '#ef444422' }}><AlertTriangle size={22} color="#ef4444"/></div>
            <div className="stat-content">
               <div className="stat-value">{stats.kpis?.duplicates || 0}</div>
               <div className="stat-label">Duplicates</div>
            </div>
          </div>
          <div className="stat-card">
              <div className="stat-icon" style={{ background: '#8b5cf622' }}><Activity size={22} color="#8b5cf6"/></div>
            <div className="stat-content">
                <div className="stat-value">{stats.active_users || 0}</div>
                <div className="stat-label">Active Users</div>
            </div>
          </div>
        </div>

        {/* TWO-COLUMN LAYOUT */}
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 350px', gap: 20 }}>
          
          {/* MAIN COLUMN: LIGHT CHARTS & SOURCES */}
          <div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20, marginBottom: 20 }}>
              
              <div className="card">
                <div className="section-title"><BarChart2 size={18} color="var(--accent)"/> Leads by Source</div>
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={stats.charts?.source || []}>
                    <XAxis dataKey="source" tick={{fontSize:10}} interval={0} angle={-25} textAnchor="end" height={50}/>
                    <Tooltip cursor={{fill:'#f3f4f6'}} contentStyle={{ borderRadius: 8, fontSize: 12 }}/>
                    <Bar dataKey="count" fill="var(--primary)" barSize={25} radius={[4,4,0,0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>

              {!location && (
                <div className="card">
                  <div className="section-title"><MapPin size={18} color="var(--accent)"/> Location Distribution</div>
                  <ResponsiveContainer width="100%" height={220}>
                    <PieChart>
                      <Pie data={stats.charts?.location || []} dataKey="count" nameKey="location" cx="50%" cy="50%" outerRadius={70}>
                        {(stats.charts?.location || []).map((_, i) => <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />)}
                      </Pie>
                      <Tooltip contentStyle={{ borderRadius: 8, fontSize: 12 }} />
                      <Legend wrapperStyle={{ fontSize: 10 }} />
                    </PieChart>
                  </ResponsiveContainer>
                </div>
              )}
              
              {location && (
                <div className="card">
                  <div className="section-title"><Smartphone size={18} color="var(--accent)"/> Device Type</div>
                  <ResponsiveContainer width="100%" height={220}>
                    <PieChart>
                      <Pie data={stats.charts?.device || []} dataKey="count" nameKey="device" cx="50%" cy="50%" outerRadius={70}>
                        {(stats.charts?.device || []).map((_, i) => <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />)}
                      </Pie>
                      <Tooltip contentStyle={{ borderRadius: 8, fontSize: 12 }} />
                      <Legend wrapperStyle={{ fontSize: 10 }} />
                    </PieChart>
                  </ResponsiveContainer>
                </div>
              )}

            </div>
          </div>

          {/* SIDEBAR COLUMN: LIVE ACTIVITY STREAM */}
          <div className="card" style={{ padding: 0, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
            <div style={{ padding: 16, borderBottom: '1px solid var(--border)', background: 'var(--bg-light)', fontWeight: 600, display: 'flex', alignItems: 'center', gap: 8 }}>
              <Activity size={16} color="var(--primary)" /> Live Activity Stream
            </div>
            <div style={{ flex: 1, overflowY: 'auto', maxHeight: 460 }}>
              {(!stats.activities || stats.activities.length === 0) ? (
                <div style={{ padding: 20, textAlign: 'center', color: 'var(--text-muted)' }}>No recent activity.</div>
              ) : (
                <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
                  {(stats.activities || []).map((act, i) => (
                    <li key={i} style={{ padding: '12px 16px', borderBottom: '1px solid var(--border)', display: 'flex', gap: 10 }}>
                      <div style={{ width: 8, height: 8, borderRadius: '50%', background: 'var(--primary)', marginTop: 6 }} />
                      <div style={{ flex: 1 }}>
                        <div style={{ fontSize: '0.85rem', color: 'var(--text-primary)' }}>
                          <strong>{act.actor}</strong> {act.action}
                        </div>
                        <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: 4 }}>
                          {act.time} {act.source && ` • [${act.source}]`}
                        </div>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>

        </div>

      </div>
    </div>
  );
}
