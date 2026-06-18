import React, { useEffect, useState } from 'react';
import api from '../api/axios.js';
import {
  BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell, Legend
} from 'recharts';
import {
  TrendingUp, Users, Target, Clock, Database, Filter, Download, Loader, AlertTriangle, RefreshCw
} from 'lucide-react';

import toast from 'react-hot-toast';

const API_ANALYTICS_URL = '/analytics.php';

/** RFC4180 CSV field escaping — wraps fields that contain commas, quotes, or newlines. */
function csvEscape(value) {
  const str = String(value ?? '');
  if (str.includes(',') || str.includes('"') || str.includes('\n') || str.includes('\r')) {
    return '"' + str.replace(/"/g, '""') + '"';
  }
  return str;
}

/** Compute win-rate percentage string (1 decimal place). Safe against divide-by-zero and Infinity. */
function computeWinRate(leads, converted) {
  const l = Number(leads);
  const c = Number(converted);
  if (!l || !isFinite(c / l)) return '0.0';
  return ((c / l) * 100).toFixed(1);
}

export default function Analytics() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  // Global Filters
  const [filters, setFilters] = useState({
    dateRange: 'all', project: '', location: '', source: '', agent: ''
  });
  const [error, setError] = useState(null);

  
  const [meta, setMeta] = useState({ projects: [], locations: [], sources: [], agents: [] });

  const loadData = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.get(API_ANALYTICS_URL, { params: filters });
      setData(res.data?.data);
    } catch {
      setError('Analytics processing failed. The system might be under high load.');
      toast.error('Failed to load Advanced Analytics');
    } finally {
      setLoading(false);
    }
  };

  const [exportLoading, setExportLoading] = useState(false);

  const handleExportCustomReport = async () => {
    if (exportLoading) return;
    setExportLoading(true);
    try {
      // Build CSV from currently loaded data — RFC4180 compliant
      const rows = [['Source', 'Leads', 'Converted', 'Win Rate']];
      (data?.sourceROI || []).forEach(s => {
        rows.push([
          s.source || 'Unknown',
          s.leads,
          s.converted,
          computeWinRate(s.leads, s.converted) + '%'
        ]);
      });
      const csv = rows.map(r => r.map(csvEscape).join(',')).join('\n');
      const blob = new Blob([csv], { type: 'text/csv' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `analytics_report_${filters.dateRange}.csv`;
      a.click();
      URL.revokeObjectURL(url);
      toast.success('Report exported!');
    } catch {
      toast.error('Export failed. Please try again.');
    } finally {
      setExportLoading(false);
    }
  };

  // Populate filter dropdowns from global meta endpoints
  useEffect(() => {
    Promise.all([
      api.get('/projects/locations.php', { params: { all_locations: 1 } }).catch(() => ({ data: { data: { locations: [] } } })),
      api.get('/users/list.php').catch(() => ({ data: { data: { users: [] } } }))
    ]).then(([locRes, usersRes]) => {
      setMeta(prev => ({
        ...prev,
        locations: locRes.data?.data?.locations || [],
        agents: usersRes.data?.data?.users || []
      }));
    });
  }, []);

  useEffect(() => { loadData(); }, [filters]);

  const updateFilter = (k, v) => setFilters(prev => ({ ...prev, [k]: v }));

  if (loading && !data) return (
    <div>
      <div className="topbar"><h1>Analytics War Room 📈</h1></div>
      <div className="loading-overlay"><div className="spinner"/><span>Synchronizing Intelligence...</span></div>
    </div>
  );

  if (error && !data) return (
    <div className="analytics-page">
       <div className="topbar"><h1>Analytics War Room 📈</h1></div>
       <div className="page" style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', height: '60vh' }}>
        <AlertTriangle size={48} color="var(--danger)" style={{ marginBottom: 16 }} />
        <h2 style={{ marginBottom: 8 }}>Analytics Engine Fault</h2>
        <p style={{ color: 'var(--text-muted)', marginBottom: 20 }}>{error}</p>
        <button className="btn btn-primary" onClick={loadData}><RefreshCw size={14} style={{ marginRight: 8 }}/> Reconnect Engine</button>
      </div>
    </div>
  );

  const A = data?.performance || {};
  const B = data?.sourceROI || [];
  const C = data?.agents || [];
  const D = data?.funnel || [];
  const E = data?.time || {};
  const F = data?.locationIntel || [];
  const G = data?.dataQuality || {};

  return (
    <div className="analytics-page">
      <div className="topbar">
        <h1>Analytics War Room 📈</h1>
        <div className="topbar-actions">
          <button
            className="btn btn-primary btn-sm"
            onClick={handleExportCustomReport}
            disabled={exportLoading || !data}
            title={!data ? 'Load data first to export' : 'Export report as CSV'}
          >
            {exportLoading ? <Loader size={14} className="spin" /> : <Download size={14}/>} {exportLoading ? 'Exporting...' : 'Export Custom Report'}
          </button>
        </div>
      </div>

      <div className="page" style={{ paddingTop: 0 }}>
        
        {/* GLOBAL FILTER BAR */}
        <div style={{ background: 'var(--bg-card)', padding: '12px 20px', display: 'flex', gap: 15, alignItems: 'center', borderBottom: '1px solid var(--border)', marginBottom: 20 }}>
          <Filter size={16} color="var(--primary)"/>
          <select value={filters.dateRange} onChange={e => updateFilter('dateRange', e.target.value)} className="form-select" style={{ width: 140 }}>
            <option value="all">Overall (All Time)</option>
            <option value="today">Today</option>
            <option value="yesterday">Yesterday</option>
            <option value="7d">Last 7 Days</option>
            <option value="30d">Last 30 Days</option>
            <option value="90d">Last 3 Months</option>
          </select>
          <select value={filters.location} onChange={e => updateFilter('location', e.target.value)} className="form-select" style={{ width: 150 }}>
            <option value="">All Locations</option>
            {meta.locations.map(l => <option key={l} value={l}>{l}</option>)}
          </select>
          <select value={filters.agent} onChange={e => updateFilter('agent', e.target.value)} className="form-select" style={{ width: 150 }}>
            <option value="">All Agents</option>
            {meta.agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        </div>

        {/* SECTION D: FUNNEL ANALYSIS (CRITICAL FIRST) */}
        <div className="card" style={{ marginBottom: 24, borderTop: '4px solid var(--primary)' }}>
          <div className="section-title"><TrendingUp size={20} color="var(--accent)"/> Funnel Analysis</div>
          <div style={{ display: 'flex', justifyContent: 'space-between', padding: '20px 0' }}>
            {D.map((stage, i) => (
              <div key={stage.name} style={{ flex: 1, textAlign: 'center', position: 'relative' }}>
                <div style={{ fontSize: '1.8rem', fontWeight: 800, color: 'var(--text-primary)' }}>{stage.count}</div>
                <div style={{ fontSize: '0.85rem', color: 'var(--text-muted)', textTransform: 'uppercase', fontWeight: 600 }}>{stage.name}</div>
                {i < D.length - 1 && (
                  <div style={{ position: 'absolute', right: -20, top: '25%', color: 'var(--danger)', fontSize: '0.8rem', fontWeight: 700, background: '#fee2e2', padding: '2px 6px', borderRadius: 10 }}>
                    -{stage.dropPercentage}%
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        {/* SECTION A: PERFORMANCE DASHBOARD */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 16, marginBottom: 24 }}>
          <div className="card" style={{ background: 'var(--primary)', color: '#fff' }}>
            <div style={{ opacity: 0.8, fontSize: '0.85rem', fontWeight: 600, textTransform: 'uppercase' }}>Conversion Rate</div>
            <div style={{ fontSize: '2.5rem', fontWeight: 800, margin: '8px 0' }}>{A.conversionRate}%</div>
            <div style={{ fontSize: '0.8rem' }}>Total Leads: {A.totalLeads} | Converted: {A.convertedLeads}</div>
          </div>
          <div className="card">
            <div style={{ color: 'var(--text-muted)', fontSize: '0.85rem', fontWeight: 600, textTransform: 'uppercase', marginBottom: 10 }}>
              <Clock size={16} style={{display:'inline', verticalAlign:'sub', marginRight:6}}/> Time Analytics
            </div>
            <div style={{ marginBottom: 12 }}>
              <div style={{ fontSize: '1.2rem', fontWeight: 700 }}>{E.avgResponseMins} <span style={{fontSize:'0.8rem', color:'var(--text-muted)', fontWeight:400}}>mins</span></div>
              <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Avg Response Time</div>
            </div>
            <div>
              <div style={{ fontSize: '1.2rem', fontWeight: 700 }}>{E.avgConversionDays} <span style={{fontSize:'0.8rem', color:'var(--text-muted)', fontWeight:400}}>days</span></div>
              <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Avg Conversion Time</div>
            </div>
          </div>
          <div className="card">
             <div style={{ color: 'var(--text-muted)', fontSize: '0.85rem', fontWeight: 600, textTransform: 'uppercase', marginBottom: 10 }}>
              <Database size={16} style={{display:'inline', verticalAlign:'sub', marginRight:6}}/> Data Quality
            </div>
            <div style={{ marginBottom: 12 }}>
              <div style={{ fontSize: '1.2rem', fontWeight: 700, color: 'var(--danger)' }}>{G.duplicatePercentage}%</div>
              <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Duplicate Leads</div>
            </div>
            <div>
              <div style={{ fontSize: '1.2rem', fontWeight: 700, color: 'var(--warning)' }}>{G.invalidPercentage}%</div>
              <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Invalid/Trash Leads</div>
            </div>
          </div>
        </div>

        {/* SECTION B & C: SOURCE ROI & AGENT INTELLIGENCE */}
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr) minmax(0,1fr)', gap: 20 }}>
          
          <div className="card">
            <div className="section-title"><Target size={18} color="var(--accent)"/> Source ROI Analytics</div>
            <div className="table-wrapper">
              <table>
                <thead><tr><th>Source</th><th>Leads</th><th>Converted</th><th>Win Rate</th></tr></thead>
                <tbody>
                  {B.map((s,i) => (
                    <tr key={i}>
                      <td>{s.source || 'Unknown'}</td>
                      <td>{s.leads}</td>
                      <td style={{color:'var(--success)', fontWeight:600}}>{s.converted}</td>
                      <td><span style={{background:'var(--bg-hover)', padding:'2px 8px', borderRadius:20, fontSize:'0.75rem'}}>{computeWinRate(s.leads, s.converted)}%</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className="card">
            <div className="section-title"><Users size={18} color="var(--accent)"/> Agent Leaderboard</div>
            <div className="table-wrapper">
              <table>
                <thead><tr><th>Agent</th><th>Assigned</th><th>Converted</th><th>Response</th></tr></thead>
                <tbody>
                  {C.map((a,i) => (
                    <tr key={i}>
                      <td><strong>{a.name}</strong></td>
                      <td>{a.assigned}</td>
                      <td style={{color:'var(--success)', fontWeight:600}}>{a.converted}</td>
                      <td>{a.avgResponseMins}m</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
          
        </div>

      </div>
    </div>
  );
}
