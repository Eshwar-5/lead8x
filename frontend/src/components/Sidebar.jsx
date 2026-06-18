import { NavLink, useNavigate } from 'react-router-dom'
import {
  LayoutDashboard, Users, Upload, GitBranch,
  Settings, LogOut, Menu, X, Shield, Zap, Activity, FolderKanban, PieChart, ToggleLeft, ToggleRight
} from 'lucide-react'
import { useState } from 'react'
import toast from 'react-hot-toast'

const getUser = () => { try { return JSON.parse(localStorage.getItem('lead8x_user')) } catch { return null } }

const navItems = [
  { to: '/',            label: 'Dashboard',    icon: LayoutDashboard, roles: null },
  { to: '/analytics',   label: 'Analytics',    icon: PieChart,        roles: ['Admin', 'Manager'] },
  { to: '/leads',       label: 'Leads',        icon: Upload,          roles: null },
  { to: '/auto-leads',  label: 'Real-time Leads', icon: Zap,           roles: ['Admin', 'Manager'] },
  { to: '/webhooks',    label: 'Webhooks',      icon: Settings,      roles: ['Admin'] },
  { to: '/webhook-logs',label: 'Webhook Logs',  icon: Activity,      roles: ['Admin'] },
  { to: '/users',       label: 'Users',        icon: Users,           roles: ['Admin', 'Manager'] },
  { to: '/distribution',label: 'Distribution', icon: GitBranch,       roles: ['Admin', 'Manager'] },
  { to: '/projects',    label: 'Project Manager', icon: FolderKanban, roles: ['Admin', 'Manager'] },
  { to: '/admin',       label: 'Admin Panel',   icon: Shield,        roles: ['Admin'] },
];

export default function Sidebar() {
  const user = getUser()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)

  const handleLogout = () => {
    localStorage.removeItem('lead8x_token')
    localStorage.removeItem('lead8x_user')
    toast.success('Logged out successfully')
    navigate('/login')
  }

  const initials = user?.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() || 'U'

  return (
    <>
      {/* Mobile toggle */}
      <button
        className="btn btn-secondary"
        style={{ position: 'fixed', top: 12, left: 12, zIndex: 200, display: 'none', padding: '8px' }}
        onClick={() => setOpen(o => !o)}
        id="sidebar-toggle"
      >
        {open ? <X size={18} /> : <Menu size={18} />}
      </button>

      <nav className={`sidebar${open ? ' open' : ''}`}>
        {/* Logo */}
        <div className="sidebar-logo">
          <h2>⚡ Lead8X</h2>
          <p>digital8x.site</p>
        </div>

        {/* Nav links */}
        <div className="nav-section" style={{ flex: 1 }}>
          <div className="nav-section-label">Navigation</div>
          {navItems.map(item => {
            if (item.roles && !item.roles.includes(user?.role)) return null
            const Icon = item.icon
            return (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/'}
                className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}
                onClick={() => setOpen(false)}
              >
                <Icon size={17} />
                {item.label}
              </NavLink>
            )
          })}
        </div>

        {/* Footer */}
        <div className="sidebar-footer">
          <div className="user-badge" style={{ marginBottom: 10 }}>
            <div className="user-avatar">{initials}</div>
            <div className="user-info">
              <strong>{user?.name || 'User'}</strong>
              <span>{user?.role || ''}</span>
            </div>
          </div>
          <button className="nav-link" style={{ color: 'var(--danger)' }} onClick={handleLogout}>
            <LogOut size={17} /> Logout
          </button>
        </div>
      </nav>
    </>
  )
}
