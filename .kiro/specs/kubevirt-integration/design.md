# Design Document: KubeVirt Integratie voor Coolify

## Overzicht

Dit document beschrijft het technische ontwerp voor de KubeVirt integratie met Coolify. Het ontwerp maakt het mogelijk om Docker host VMs automatisch te provisioneren binnen Kubernetes clusters, waardoor Coolify volledig op Kubernetes kan draaien zonder externe infrastructuur.

De implementatie bestaat uit de volgende hoofdcomponenten:
1. **Umbrella Helm Chart** - Orchestreert alle componenten
2. **KubeVirt Installer** - Automatische KubeVirt setup (niet-OpenShift)
3. **VM Provisioner** - VirtualMachine CR templates
4. **Registration Service** - Automatische Coolify server registratie
5. **Health Monitor** - VM status monitoring

## Architectuur

### Hoog-niveau Architectuur

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              Kubernetes Cluster                                  │
│                                                                                  │
│  ┌────────────────────────────────────────────────────────────────────────────┐ │
│  │                    coolify-platform Helm Chart                             │ │
│  │                                                                            │ │
│  │  ┌──────────────────────────────────────────────────────────────────────┐ │ │
│  │  │                      Pre-Install Hooks                                │ │ │
│  │  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────┐  │ │ │
│  │  │  │ Platform        │  │ KubeVirt        │  │ SSH Key             │  │ │ │
│  │  │  │ Detection       │─▶│ Installer       │  │ Generator           │  │ │ │
│  │  │  │                 │  │ (if needed)     │  │                     │  │ │ │
│  │  │  └─────────────────┘  └─────────────────┘  └─────────────────────┘  │ │ │
│  │  └──────────────────────────────────────────────────────────────────────┘ │ │
│  │                                     │                                      │ │
│  │                                     ▼                                      │ │
│  │  ┌──────────────────────────────────────────────────────────────────────┐ │ │
│  │  │                         Main Resources                                │ │ │
│  │  │                                                                       │ │ │
│  │  │  ┌─────────────────────────┐    ┌──────────────────────────────────┐ │ │ │
│  │  │  │   coolify namespace     │    │    coolify-vms namespace         │ │ │ │
│  │  │  │                         │    │                                  │ │ │ │
│  │  │  │  ┌───────────────────┐  │    │  ┌────────────────────────────┐ │ │ │ │
│  │  │  │  │ Coolify Subchart  │  │    │  │ VirtualMachine CRs        │ │ │ │ │
│  │  │  │  │ - Deployment      │  │SSH │  │ - docker-host-0           │ │ │ │ │
│  │  │  │  │ - PostgreSQL      │◀─┼────┼──│ - docker-host-1           │ │ │ │ │
│  │  │  │  │ - Redis           │  │    │  │ - docker-host-N           │ │ │ │ │
│  │  │  │  │ - Soketi          │  │    │  └────────────────────────────┘ │ │ │ │
│  │  │  │  │ - Ingress/Route   │  │    │                                  │ │ │ │
│  │  │  │  └───────────────────┘  │    │  ┌────────────────────────────┐ │ │ │ │
│  │  │  │                         │    │  │ Services (SSH)            │ │ │ │ │
│  │  │  │  ┌───────────────────┐  │    │  │ - docker-host-0-ssh       │ │ │ │ │
│  │  │  │  │ Secrets           │  │    │  │ - docker-host-1-ssh       │ │ │ │ │
│  │  │  │  │ - SSH Keys        │  │    │  └────────────────────────────┘ │ │ │ │
│  │  │  │  │ - API Credentials │  │    │                                  │ │ │ │
│  │  │  │  └───────────────────┘  │    │  ┌────────────────────────────┐ │ │ │ │
│  │  │  └─────────────────────────┘    │  │ DataVolumes               │ │ │ │ │
│  │  │                                  │  │ (Persistent Disks)        │ │ │ │ │
│  │  │                                  │  └────────────────────────────┘ │ │ │ │
│  │  │                                  └──────────────────────────────────┘ │ │ │
│  │  └──────────────────────────────────────────────────────────────────────┘ │ │
│  │                                     │                                      │ │
│  │                                     ▼                                      │ │
│  │  ┌──────────────────────────────────────────────────────────────────────┐ │ │
│  │  │                       Post-Install Hooks                              │ │ │
│  │  │  ┌─────────────────────┐  ┌─────────────────────────────────────┐   │ │ │
│  │  │  │ Registration Job    │  │ Health Check CronJob                │   │ │ │
│  │  │  │ (Coolify API)       │  │ (Periodic monitoring)               │   │ │ │
│  │  │  └─────────────────────┘  └─────────────────────────────────────┘   │ │ │
│  │  └──────────────────────────────────────────────────────────────────────┘ │ │
│  └────────────────────────────────────────────────────────────────────────────┘ │
│                                                                                  │
│  ┌────────────────────────────────────────────────────────────────────────────┐ │
│  │                    kubevirt namespace (KubeVirt System)                    │ │
│  │  virt-operator │ virt-controller │ virt-handler │ virt-api │ CDI          │ │
│  └────────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### Component Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                     coolify-platform Helm Chart                      │
│                                                                      │
│  values.yaml                                                         │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │ coolify:          # Subchart configuration                     │ │
│  │   config:                                                      │ │
│  │     appUrl: "https://..."                                      │ │
│  │                                                                │ │
│  │ dockerHosts:      # VM configuration                           │ │
│  │   enabled: true                                                │ │
│  │   count: 2                                                     │ │
│  │   resources:                                                   │ │
│  │     cores: 2                                                   │ │
│  │     memory: 4Gi                                                │ │
│  │                                                                │ │
│  │ kubevirt:         # KubeVirt installer config                  │ │
│  │   install: true                                                │ │
│  │   version: "v1.2.0"                                            │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                      │
│  templates/                                                          │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │ pre-install/                                                   │ │
│  │   ├── rbac.yaml           # ServiceAccount, Role, RoleBinding  │ │
│  │   ├── kubevirt-installer.yaml  # Job: install KubeVirt        │ │
│  │   └── ssh-keygen.yaml     # Job: generate SSH keys             │ │
│  │                                                                │ │
│  │ docker-hosts/                                                  │ │
│  │   ├── namespace.yaml      # coolify-vms namespace              │ │
│  │   ├── virtualmachine.yaml # VirtualMachine CRs                 │ │
│  │   ├── datavolume.yaml     # Persistent disks                   │ │
│  │   ├── service.yaml        # SSH Services                       │ │
│  │   ├── cloudinit-secret.yaml  # Cloud-init config              │ │
│  │   └── networkpolicy.yaml  # Network access rules               │ │
│  │                                                                │ │
│  │ post-install/                                                  │ │
│  │   ├── registration-job.yaml  # Register VMs in Coolify        │ │
│  │   └── health-cronjob.yaml    # Periodic health checks         │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                      │
│  charts/                                                             │
│  └── coolify/                # Existing Coolify Helm chart          │
└─────────────────────────────────────────────────────────────────────┘
```

## Deployment Flow

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              Helm Install Flow                                   │
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ Phase 1: Pre-Install Hooks (hook-weight: -10 to -1)                             │
│                                                                                  │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────────────────┐ │
│  │ 1. Create RBAC  │───▶│ 2. Platform     │───▶│ 3. Install KubeVirt        │ │
│  │    Resources    │    │    Detection    │    │    (if not OpenShift)      │ │
│  │    (weight:-10) │    │    (weight:-9)  │    │    (weight:-8)             │ │
│  └─────────────────┘    └─────────────────┘    └─────────────────────────────┘ │
│                                                           │                      │
│                                                           ▼                      │
│                                               ┌─────────────────────────────┐   │
│                                               │ 4. Wait for KubeVirt Ready  │   │
│                                               │    (timeout: 10min)         │   │
│                                               └─────────────────────────────┘   │
│                                                           │                      │
│                                                           ▼                      │
│                                               ┌─────────────────────────────┐   │
│                                               │ 5. Generate SSH Keys        │   │
│                                               │    (weight: -1)             │   │
│                                               └─────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ Phase 2: Main Resources                                                          │
│                                                                                  │
│  ┌──────────────────────────────────────────────────────────────────────────┐  │
│  │ Parallel Resource Creation                                                │  │
│  │                                                                           │  │
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────┐  │  │
│  │  │ Coolify         │  │ VM Namespace    │  │ Cloud-init Secrets      │  │  │
│  │  │ Subchart        │  │                 │  │                         │  │  │
│  │  └─────────────────┘  └─────────────────┘  └─────────────────────────┘  │  │
│  │                                                                           │  │
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────┐  │  │
│  │  │ DataVolumes     │  │ VirtualMachines │  │ Services                │  │  │
│  │  │ (Disk Import)   │  │                 │  │ (SSH access)            │  │  │
│  │  └─────────────────┘  └─────────────────┘  └─────────────────────────┘  │  │
│  │                                                                           │  │
│  │  ┌─────────────────┐                                                     │  │
│  │  │ NetworkPolicies │                                                     │  │
│  │  │ (if enabled)    │                                                     │  │
│  │  └─────────────────┘                                                     │  │
│  └──────────────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│ Phase 3: Post-Install Hooks (hook-weight: 1 to 10)                              │
│                                                                                  │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ 6. Wait for Coolify Ready                                                │   │
│  │    - Poll /api/health endpoint                                           │   │
│  │    - Timeout: 5 minutes                                                  │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                                      │                                          │
│                                      ▼                                          │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ 7. Wait for VMs Ready (per VM)                                           │   │
│  │    - Check VMI status = Running                                          │   │
│  │    - Check SSH port 22 reachable                                         │   │
│  │    - Check Docker daemon running                                         │   │
│  │    - Timeout: 5 minutes per VM                                           │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                                      │                                          │
│                                      ▼                                          │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ 8. Register VMs in Coolify (per VM)                                      │   │
│  │    - POST /api/v1/servers                                                │   │
│  │    - Payload: name, ip, port, user, private_key                          │   │
│  │    - Store server_id in ConfigMap                                        │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                                      │                                          │
│                                      ▼                                          │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ 9. Create Health Check CronJob                                           │   │
│  │    - Schedule: every minute                                              │   │
│  │    - Checks: SSH, Docker, disk, memory                                   │   │
│  │    - Updates: VM labels, Events                                          │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              Installation Complete                               │
│                                                                                  │
│  ✓ KubeVirt installed (if needed)                                               │
│  ✓ Coolify running and accessible                                               │
│  ✓ Docker host VMs running                                                      │
│  ✓ VMs registered in Coolify                                                    │
│  ✓ Health monitoring active                                                     │
│                                                                                  │
│  → User can now deploy applications via Coolify UI                              │
└─────────────────────────────────────────────────────────────────────────────────┘
```

## Data Model

### VirtualMachine Specification

```yaml
apiVersion: kubevirt.io/v1
kind: VirtualMachine
metadata:
  name: docker-host-{{ index }}
  namespace: {{ vmNamespace }}
  labels:
    app.kubernetes.io/name: coolify-docker-host
    app.kubernetes.io/instance: {{ releaseName }}
    app.kubernetes.io/component: docker-host
    app.kubernetes.io/managed-by: Helm
    coolify.io/docker-host: "true"
    coolify.io/host-index: "{{ index }}"
  annotations:
    coolify.io/created-at: "{{ timestamp }}"
spec:
  running: true

  dataVolumeTemplates:
    - metadata:
        name: docker-host-{{ index }}-rootdisk
      spec:
        source:
          registry:
            url: "docker://{{ image }}"
        pvc:
          accessModes: [ReadWriteOnce]
          resources:
            requests:
              storage: {{ diskSize }}
          storageClassName: {{ storageClass }}

  template:
    metadata:
      labels:
        kubevirt.io/vm: docker-host-{{ index }}
        coolify.io/docker-host: "true"
    spec:
      domain:
        cpu:
          cores: {{ cores }}
          sockets: 1
          threads: 1
        memory:
          guest: {{ memory }}
        devices:
          disks:
            - name: rootdisk
              disk:
                bus: virtio
            - name: cloudinit
              disk:
                bus: virtio
          interfaces:
            - name: default
              masquerade: {}
        resources:
          requests:
            memory: {{ memory }}
          limits:
            memory: {{ memory }}

      networks:
        - name: default
          pod: {}

      volumes:
        - name: rootdisk
          dataVolume:
            name: docker-host-{{ index }}-rootdisk
        - name: cloudinit
          cloudInitNoCloud:
            secretRef:
              name: docker-host-cloudinit
```

### Cloud-init Configuration

```yaml
#cloud-config
# =============================================================================
# Coolify Docker Host VM Configuration
# =============================================================================

# Hostname
hostname: {{ hostname }}
fqdn: {{ hostname }}.{{ namespace }}.svc.cluster.local
manage_etc_hosts: true

# =============================================================================
# User Configuration
# =============================================================================
users:
  - name: coolify
    gecos: Coolify Service User
    groups: [docker, sudo, wheel, adm]
    shell: /bin/bash
    sudo: ['ALL=(ALL) NOPASSWD:ALL']
    lock_passwd: false
    ssh_authorized_keys:
      - {{ sshPublicKey }}

# Disable password authentication
ssh_pwauth: false

# =============================================================================
# Package Installation
# =============================================================================
package_update: true
package_upgrade: true

packages:
  # Docker
  - docker
  - docker-compose

  # Utilities
  - curl
  - wget
  - git
  - vim
  - htop
  - jq

  # Networking
  - net-tools
  - bind-utils

  # Security (optional)
  {{- if .securityHardening }}
  - fail2ban
  {{- end }}

# =============================================================================
# Docker Configuration
# =============================================================================
write_files:
  - path: /etc/docker/daemon.json
    content: |
      {
        "log-driver": "json-file",
        "log-opts": {
          "max-size": "10m",
          "max-file": "3"
        },
        "storage-driver": "overlay2"
      }

# =============================================================================
# System Configuration
# =============================================================================
runcmd:
  # Enable and start Docker
  - systemctl enable docker
  - systemctl start docker

  # Add coolify user to docker group
  - usermod -aG docker coolify

  # SSH Hardening
  - sed -i 's/#PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
  - sed -i 's/#PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
  - sed -i 's/#PubkeyAuthentication.*/PubkeyAuthentication yes/' /etc/ssh/sshd_config
  - systemctl restart sshd

  {{- if .securityHardening }}
  # Firewall
  - systemctl enable firewalld
  - systemctl start firewalld
  - firewall-cmd --permanent --add-service=ssh
  - firewall-cmd --reload

  # Fail2ban
  - systemctl enable fail2ban
  - systemctl start fail2ban
  {{- end }}

  # Signal boot complete
  - touch /var/lib/cloud/instance/boot-finished
  - echo "Docker host ready" > /var/log/coolify-ready

# =============================================================================
# Final Message
# =============================================================================
final_message: |
  Coolify Docker Host initialized successfully!
  Hostname: {{ hostname }}
  Cloud-init completed in $UPTIME seconds.
```

### SSH Key Secret

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: coolify-docker-host-ssh
  namespace: {{ namespace }}
  labels:
    app.kubernetes.io/name: coolify-platform
    app.kubernetes.io/component: ssh-keys
  annotations:
    coolify.io/generated-at: "{{ timestamp }}"
    helm.sh/resource-policy: keep
type: kubernetes.io/ssh-auth
data:
  ssh-privatekey: {{ privateKey | b64enc }}
  ssh-publickey: {{ publicKey | b64enc }}
```

### Service Definition

```yaml
apiVersion: v1
kind: Service
metadata:
  name: docker-host-{{ index }}-ssh
  namespace: {{ vmNamespace }}
  labels:
    app.kubernetes.io/name: coolify-docker-host
    app.kubernetes.io/instance: {{ releaseName }}
    coolify.io/docker-host: "true"
    coolify.io/host-index: "{{ index }}"
spec:
  type: {{ serviceType }}  # ClusterIP | NodePort | LoadBalancer
  selector:
    kubevirt.io/vm: docker-host-{{ index }}
  ports:
    - name: ssh
      port: 22
      targetPort: 22
      protocol: TCP
      {{- if eq serviceType "NodePort" }}
      nodePort: {{ nodePort }}
      {{- end }}
```

## Platform-Specific Adaptations

### OpenShift/OKD

```yaml
# values-openshift.yaml additions

# Use OpenShift Virtualization (skip KubeVirt install)
kubevirt:
  install: false

# Use Routes instead of Ingress
coolify:
  ingress:
    enabled: false
  route:
    enabled: true
    host: coolify.apps.{{ clusterDomain }}

# OpenShift-specific settings
openshift:
  enabled: true

  # SCC configuration
  scc:
    create: false  # Use anyuid manually

  # Network policies for cross-namespace
  networkPolicy:
    enabled: true
```

### K3s

```yaml
# values-k3s.yaml additions

# Install KubeVirt
kubevirt:
  install: true
  version: "v1.2.0"

# Use Traefik Ingress
coolify:
  ingress:
    enabled: true
    className: traefik
    annotations:
      traefik.ingress.kubernetes.io/router.tls: "true"

# K3s local-path storage
dockerHosts:
  storageClass: local-path

# Lower resources for edge/homelab
dockerHosts:
  resources:
    cores: 2
    memory: 2Gi
    disk: 30Gi
```

## Security Design

### RBAC Structure

```yaml
# Installer ServiceAccount (pre-install)
apiVersion: v1
kind: ServiceAccount
metadata:
  name: kubevirt-installer
  namespace: {{ namespace }}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: kubevirt-installer
rules:
  # KubeVirt installation
  - apiGroups: [""]
    resources: ["namespaces"]
    verbs: ["create", "get", "list"]
  - apiGroups: ["apiextensions.k8s.io"]
    resources: ["customresourcedefinitions"]
    verbs: ["create", "get", "list", "watch"]
  - apiGroups: ["kubevirt.io"]
    resources: ["*"]
    verbs: ["*"]
  - apiGroups: ["cdi.kubevirt.io"]
    resources: ["*"]
    verbs: ["*"]
  # Deployments for operators
  - apiGroups: ["apps"]
    resources: ["deployments"]
    verbs: ["create", "get", "list", "watch"]
---
# Registration Job ServiceAccount (post-install)
apiVersion: v1
kind: ServiceAccount
metadata:
  name: vm-registrator
  namespace: {{ namespace }}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: vm-registrator
  namespace: {{ vmNamespace }}
rules:
  - apiGroups: ["kubevirt.io"]
    resources: ["virtualmachines", "virtualmachineinstances"]
    verbs: ["get", "list", "watch"]
  - apiGroups: [""]
    resources: ["secrets"]
    verbs: ["get"]
  - apiGroups: [""]
    resources: ["configmaps"]
    verbs: ["create", "update", "get"]
```

### Network Security

```yaml
# NetworkPolicy: Allow Coolify to SSH into VMs
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: allow-coolify-ssh
  namespace: {{ vmNamespace }}
spec:
  podSelector:
    matchLabels:
      coolify.io/docker-host: "true"
  policyTypes:
    - Ingress
  ingress:
    - from:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: {{ coolifyNamespace }}
          podSelector:
            matchLabels:
              app.kubernetes.io/name: coolify
      ports:
        - protocol: TCP
          port: 22
---
# NetworkPolicy: Allow VMs to access internet (for Docker pulls)
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: allow-egress
  namespace: {{ vmNamespace }}
spec:
  podSelector:
    matchLabels:
      coolify.io/docker-host: "true"
  policyTypes:
    - Egress
  egress:
    - {}  # Allow all egress
```

## Error Handling

### KubeVirt Installation Failures

| Error | Detection | Handling |
|-------|-----------|----------|
| No KVM support | Check `/dev/kvm` | Fail with clear message |
| Operator timeout | 10min timeout | Retry 3x, then fail |
| CDI failure | Health check | Continue without CDI, warn |
| Version mismatch | API version check | Use compatible version |

### VM Provisioning Failures

| Error | Detection | Handling |
|-------|-----------|----------|
| Storage unavailable | DataVolume status | Fail with storage class info |
| Image pull failure | VMI events | Retry with backoff |
| Boot failure | VMI status | Log events, mark unhealthy |
| Cloud-init failure | SSH unreachable | Timeout, recreate VM |

### Registration Failures

| Error | Detection | Handling |
|-------|-----------|----------|
| Coolify unavailable | Health endpoint | Retry with exponential backoff |
| API auth failure | 401/403 response | Log warning, skip registration |
| SSH unreachable | Connection timeout | Retry, mark VM unhealthy |
| Duplicate server | API response | Skip (idempotent) |

## Testing Strategy

### Unit Tests (Helm)

```yaml
# tests/virtualmachine_test.yaml
suite: VirtualMachine Tests
templates:
  - docker-hosts/virtualmachine.yaml
tests:
  - it: should create correct number of VMs
    set:
      dockerHosts.count: 3
    asserts:
      - hasDocuments:
          count: 3

  - it: should set correct resources
    set:
      dockerHosts.resources.cores: 4
      dockerHosts.resources.memory: 8Gi
    asserts:
      - equal:
          path: spec.template.spec.domain.cpu.cores
          value: 4
      - equal:
          path: spec.template.spec.domain.memory.guest
          value: 8Gi
```

### Integration Tests

```bash
#!/bin/bash
# test/integration/test-installation.sh

# Setup: Create K3s cluster with KubeVirt
k3d cluster create test-cluster
kubectl apply -f https://github.com/kubevirt/kubevirt/releases/download/v1.2.0/kubevirt-operator.yaml

# Test: Install chart
helm install coolify-platform ./charts/coolify-platform \
  -f values-k3s.yaml \
  --set dockerHosts.count=1 \
  --wait --timeout=15m

# Verify: Coolify accessible
curl -sf http://coolify.localhost/api/health || exit 1

# Verify: VM running
kubectl get vmi -n coolify-vms docker-host-0 -o jsonpath='{.status.phase}' | grep -q Running || exit 1

# Verify: SSH accessible
kubectl run ssh-test --rm -it --image=alpine -- \
  nc -z docker-host-0-ssh.coolify-vms.svc 22 || exit 1

# Cleanup
k3d cluster delete test-cluster
```

## Versioning Strategy

| Chart Version | KubeVirt Version | Coolify Version | Breaking Changes |
|---------------|------------------|-----------------|------------------|
| 0.1.x | >=1.0.0 | >=4.0.0 | Initial release |
| 0.2.x | >=1.0.0 | >=4.0.0 | Values structure changes |
| 1.0.x | >=1.0.0 | >=4.0.0 | Stable API |

## Future Considerations

### Phase 2 Enhancements

1. **GPU Passthrough** - Voor ML workloads
2. **Live Migration** - Zero-downtime VM moves
3. **VM Templates** - Pre-configured images
4. **Auto-scaling** - Automatisch VMs toevoegen bij load

### Integration Points

1. **Coolify API** - Server management endpoints
2. **Prometheus** - VM metrics
3. **Loki** - VM logs
4. **Velero** - Backup integration
