# Coolify Helm Chart

Deploy [Coolify](https://coolify.io) - the self-hostable Heroku/Netlify/Vercel alternative - on Kubernetes.

**Supported Platforms:**
- Kubernetes (vanilla)
- K3s / K3d
- OKD (Community OpenShift)
- Red Hat OpenShift (OCP)

## Prerequisites

- Kubernetes 1.23+ / K3s 1.23+ / OKD 4.10+ / OpenShift 4.10+
- Helm 3.8+
- PV provisioner support (for persistence)

## Quick Start

### Vanilla Kubernetes / K3s

```bash
helm install coolify ./charts/coolify \
  --namespace coolify \
  --create-namespace \
  -f charts/coolify/values-k3s.yaml  # or your custom values
```

### OKD / OpenShift

```bash
# Create project and grant required SCC
oc new-project coolify
oc adm policy add-scc-to-user anyuid -z coolify -n coolify

# Install
helm install coolify ./charts/coolify \
  --namespace coolify \
  -f charts/coolify/values-okd.yaml  # or values-openshift.yaml
```

## Platform-Specific Values Files

| File | Platform | Notes |
|------|----------|-------|
| `values.yaml` | Default | Vanilla Kubernetes with Ingress |
| `values-k3s.yaml` | K3s/K3d | Traefik ingress, local-path storage |
| `values-okd.yaml` | OKD | OpenShift Routes, anyuid SCC |
| `values-openshift.yaml` | OpenShift | OCP with enterprise options |

## Configuration

### Key Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| `coolify.config.appUrl` | Full URL where Coolify is accessible | `https://coolify.example.com` |
| `coolify.replicaCount` | Number of Coolify replicas | `1` |
| `ingress.enabled` | Enable Kubernetes Ingress | `true` |
| `route.enabled` | Enable OpenShift Route | `false` |
| `postgresql.enabled` | Use bundled PostgreSQL | `true` |
| `redis.enabled` | Use bundled Redis | `true` |
| `persistence.enabled` | Enable persistent storage | `true` |
| `persistence.size` | Storage size | `20Gi` |

### Ingress vs Route

**Kubernetes/K3s:** Use `ingress.enabled: true`

```yaml
ingress:
  enabled: true
  className: "nginx"  # or "traefik" for K3s
  host: "coolify.example.com"
  tls:
    enabled: true
```

**OKD/OpenShift:** Use `route.enabled: true`

```yaml
ingress:
  enabled: false
route:
  enabled: true
  host: "coolify.apps.cluster.example.com"
  tls:
    enabled: true
    termination: "edge"
```

### Storage Classes

| Platform | Common Storage Classes |
|----------|----------------------|
| K3s | `local-path` (default) |
| EKS | `gp2`, `gp3` |
| GKE | `standard`, `premium-rwo` |
| AKS | `managed-premium`, `managed-standard` |
| OKD/OpenShift | `gp2`, `ocs-storagecluster-ceph-rbd` |

```yaml
global:
  storageClass: "your-storage-class"
```

### Using External Databases

**External PostgreSQL:**

```yaml
postgresql:
  enabled: false
  external:
    host: "postgres.example.com"
    port: 5432
    database: "coolify"
    username: "coolify"
    existingSecret: "my-postgres-secret"
    existingSecretKey: "password"
```

**External Redis:**

```yaml
redis:
  enabled: false
  external:
    host: "redis.example.com"
    port: 6379
    existingSecret: "my-redis-secret"
    existingSecretKey: "password"
```

## OpenShift/OKD Security

OpenShift requires additional permissions for containers that need specific UIDs.

### Option 1: Use anyuid SCC (Recommended)

```bash
# Grant anyuid SCC to the service account
oc adm policy add-scc-to-user anyuid -z coolify -n coolify
```

### Option 2: Create Custom SCC

Set `openshift.createSCC: true` in values (requires cluster-admin).

### Image Pull from ghcr.io

If your cluster can't pull from ghcr.io directly:

```bash
# Create pull secret
oc create secret docker-registry ghcr-secret \
  --docker-server=ghcr.io \
  --docker-username=YOUR_GITHUB_USERNAME \
  --docker-password=YOUR_GITHUB_PAT \
  -n coolify

# Link to service account
oc secrets link coolify ghcr-secret --for=pull
```

Then in values:
```yaml
imagePullSecrets:
  - name: ghcr-secret
```

## Production Recommendations

### 1. Set a Static APP_KEY

```bash
# Generate a key
head -c 32 /dev/urandom | base64
```

```yaml
security:
  appKey: "base64:YOUR_GENERATED_KEY"
```

### 2. Use External Managed Databases

For production, consider using managed PostgreSQL and Redis services.

### 3. Configure Resource Limits

```yaml
coolify:
  resources:
    requests:
      memory: "1Gi"
      cpu: "500m"
    limits:
      memory: "4Gi"
      cpu: "4000m"
```

### 4. Use RWX Storage for HA

If scaling to multiple replicas:

```yaml
coolify:
  replicaCount: 2
persistence:
  accessModes:
    - ReadWriteMany
```

### 5. Enable TLS

**With cert-manager (Kubernetes/K3s):**

```yaml
ingress:
  annotations:
    cert-manager.io/cluster-issuer: letsencrypt-prod
  tls:
    enabled: true
```

**With OpenShift default router certificate:**

```yaml
route:
  tls:
    enabled: true
    termination: "edge"
```

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Kubernetes Cluster                    │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────┐  │
│  │   Ingress/  │  │   Coolify   │  │     Soketi      │  │
│  │    Route    │──│ Deployment  │──│   Deployment    │  │
│  └─────────────┘  └─────────────┘  └─────────────────┘  │
│                          │                               │
│         ┌────────────────┼────────────────┐              │
│         ▼                ▼                ▼              │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐      │
│  │ PostgreSQL  │  │    Redis    │  │  Coolify    │      │
│  │ StatefulSet │  │ StatefulSet │  │   Storage   │      │
│  │   (PVC)     │  │   (PVC)     │  │   (PVC)     │      │
│  └─────────────┘  └─────────────┘  └─────────────┘      │
└─────────────────────────────────────────────────────────┘
                          │
                          │ SSH
                          ▼
              ┌─────────────────────┐
              │   Docker Servers    │
              │  (External targets) │
              └─────────────────────┘
```

## Limitations

When running Coolify on Kubernetes:

- **No "localhost" server**: The feature to deploy to the same host Coolify runs on is not available
- **External Docker servers only**: You must add Docker servers via SSH as deployment targets
- **SSH connectivity**: Ensure your K8s pods can reach your Docker servers via SSH

## KubeVirt Integration (Docker Hosts Inside Kubernetes)

Instead of using external Docker servers, you can run Docker host VMs **inside** your Kubernetes cluster using KubeVirt. This keeps everything on a single platform.

### Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                       Kubernetes Cluster                             │
│                                                                      │
│  ┌──────────────────────┐     ┌─────────────────────────────────┐   │
│  │  coolify namespace   │     │    coolify-vms namespace        │   │
│  │                      │     │                                 │   │
│  │  ┌────────────────┐  │ SSH │  ┌───────────────────────────┐  │   │
│  │  │ Coolify        │──┼─────┼─▶│  KubeVirt VM              │  │   │
│  │  │ Control Plane  │  │     │  │  ┌─────────────────────┐  │  │   │
│  │  │ (This Chart)   │  │     │  │  │ Docker Engine       │  │  │   │
│  │  └────────────────┘  │     │  │  │ ┌─────┐ ┌───────┐  │  │  │   │
│  │                      │     │  │  │ │App 1│ │App 2  │  │  │  │   │
│  │  ┌────────────────┐  │     │  │  │ └─────┘ └───────┘  │  │  │   │
│  │  │ PostgreSQL     │  │     │  │  └─────────────────────┘  │  │   │
│  │  │ Redis          │  │     │  └───────────────────────────┘  │   │
│  │  └────────────────┘  │     └─────────────────────────────────┘   │
│  └──────────────────────┘                                           │
└─────────────────────────────────────────────────────────────────────┘
```

### Platform Support

| Platform | KubeVirt Solution | Installation |
|----------|-------------------|--------------|
| OpenShift/OKD | OpenShift Virtualization | OperatorHub |
| K3s | Community KubeVirt | Manual YAML |
| Kubernetes | Community KubeVirt | Manual YAML |

### Prerequisites

```bash
# Nodes must have KVM support (bare metal or nested virtualization)
egrep -c '(vmx|svm)' /proc/cpuinfo  # Should be > 0
ls /dev/kvm                          # Should exist
```

### Installing KubeVirt

**OpenShift/OKD:**

```bash
# Install via OperatorHub (OpenShift Console)
# Or via CLI:
cat <<EOF | oc apply -f -
apiVersion: operators.coreos.com/v1alpha1
kind: Subscription
metadata:
  name: kubevirt-hyperconverged
  namespace: openshift-cnv
spec:
  channel: stable
  name: kubevirt-hyperconverged
  source: redhat-operators
  sourceNamespace: openshift-marketplace
EOF
```

**K3s / Kubernetes:**

```bash
# Install KubeVirt
export KUBEVIRT_VERSION=$(curl -s https://api.github.com/repos/kubevirt/kubevirt/releases/latest | grep tag_name | cut -d '"' -f 4)
kubectl create -f https://github.com/kubevirt/kubevirt/releases/download/${KUBEVIRT_VERSION}/kubevirt-operator.yaml
kubectl create -f https://github.com/kubevirt/kubevirt/releases/download/${KUBEVIRT_VERSION}/kubevirt-cr.yaml

# Install CDI (Containerized Data Importer)
export CDI_VERSION=$(curl -s https://api.github.com/repos/kubevirt/containerized-data-importer/releases/latest | grep tag_name | cut -d '"' -f 4)
kubectl create -f https://github.com/kubevirt/containerized-data-importer/releases/download/${CDI_VERSION}/cdi-operator.yaml
kubectl create -f https://github.com/kubevirt/containerized-data-importer/releases/download/${CDI_VERSION}/cdi-cr.yaml

# Wait for ready
kubectl wait -n kubevirt kv kubevirt --for=condition=Available --timeout=300s

# Install virtctl CLI
curl -L -o virtctl https://github.com/kubevirt/kubevirt/releases/download/${KUBEVIRT_VERSION}/virtctl-${KUBEVIRT_VERSION}-linux-amd64
chmod +x virtctl && sudo mv virtctl /usr/local/bin/
```

### Creating a Docker Host VM

```yaml
# docker-host-vm.yaml
apiVersion: v1
kind: Namespace
metadata:
  name: coolify-vms
---
apiVersion: kubevirt.io/v1
kind: VirtualMachine
metadata:
  name: docker-host
  namespace: coolify-vms
spec:
  running: true
  template:
    metadata:
      labels:
        app: docker-host
    spec:
      domain:
        cpu:
          cores: 2
        memory:
          guest: 4Gi
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
      networks:
        - name: default
          pod: {}
      volumes:
        - name: rootdisk
          containerDisk:
            image: quay.io/containerdisks/fedora:40
        - name: cloudinit
          cloudInitNoCloud:
            userData: |
              #cloud-config
              hostname: docker-host
              user: coolify
              ssh_pwauth: true
              chpasswd:
                list: |
                  coolify:changeme
                expire: false
              ssh_authorized_keys:
                - ssh-ed25519 AAAA... your-coolify-ssh-key
              package_update: true
              packages:
                - docker
                - docker-compose
              runcmd:
                - systemctl enable --now docker
                - usermod -aG docker coolify
---
apiVersion: v1
kind: Service
metadata:
  name: docker-host-ssh
  namespace: coolify-vms
spec:
  type: ClusterIP
  selector:
    app: docker-host
  ports:
    - name: ssh
      port: 22
      targetPort: 22
```

```bash
kubectl apply -f docker-host-vm.yaml
```

### Connecting Coolify to the VM

1. Wait for VM to be ready:
   ```bash
   kubectl get vmi -n coolify-vms
   ```

2. In Coolify UI, add a new server:
   - **IP/Hostname**: `docker-host-ssh.coolify-vms.svc.cluster.local`
   - **Port**: `22`
   - **User**: `coolify`
   - **SSH Key**: Your private key

### Automation Strategy (Future)

For fully automated deployment, an umbrella Helm chart can orchestrate:

```
coolify-platform/
├── Chart.yaml
├── values.yaml
├── templates/
│   ├── kubevirt-install-job.yaml      # Installs KubeVirt (pre-install hook)
│   ├── docker-host-vm.yaml            # VirtualMachine CR(s)
│   ├── docker-host-service.yaml       # SSH access to VMs
│   └── coolify-registration-job.yaml  # Auto-register VMs via API
└── charts/
    └── coolify/                        # This chart as subchart
```

**Automation Components:**

| Component | Purpose |
|-----------|---------|
| Platform Detection | Skip KubeVirt install on OpenShift |
| KubeVirt Install Job | Pre-install hook for K3s/K8s |
| VM Templates | VirtualMachine CRs with cloud-init |
| SSH Key Generation | Auto-generate or use existing |
| Auto-Registration | Job calling Coolify API post-install |

**Deployment Flow:**

```
helm install coolify-platform ./coolify-platform -f values-k3s.yaml
                                    │
                    ┌───────────────┴───────────────┐
                    ▼                               ▼
        ┌───────────────────┐           ┌───────────────────┐
        │ 1. Install        │           │ 2. Deploy Coolify │
        │    KubeVirt       │           │    (subchart)     │
        │    (if needed)    │           │                   │
        └───────────────────┘           └───────────────────┘
                    │                               │
                    └───────────────┬───────────────┘
                                    ▼
                    ┌───────────────────────────────┐
                    │ 3. Create Docker Host VMs     │
                    │    - SSH keys (generated)     │
                    │    - cloud-init (Docker)      │
                    └───────────────────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────┐
                    │ 4. Auto-Register VMs          │
                    │    in Coolify via API         │
                    └───────────────────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────┐
                    │ Ready! Deploy apps via UI     │
                    └───────────────────────────────┘
```

**Example automated installation:**

```bash
# K3s - fully automated
helm install coolify-platform ./coolify-platform \
  --namespace coolify \
  --create-namespace \
  -f values-k3s.yaml \
  --set coolify.config.appUrl=https://coolify.example.com \
  --set dockerHosts.count=2 \
  --set dockerHosts.resources.memory=4Gi \
  --set dockerHosts.resources.cores=2

# OKD - fully automated
oc new-project coolify
oc adm policy add-scc-to-user anyuid -z coolify -n coolify
helm install coolify-platform ./coolify-platform \
  --namespace coolify \
  -f values-okd.yaml \
  --set coolify.config.appUrl=https://coolify.apps.okd.example.com \
  --set dockerHosts.count=2
```

**Scaling Docker hosts:**

```bash
helm upgrade coolify-platform ./coolify-platform \
  --set dockerHosts.count=5 \
  --reuse-values
```

### Benefits of KubeVirt Approach

| Benefit | Description |
|---------|-------------|
| Single Platform | Everything runs on Kubernetes |
| Full Docker | No container restrictions |
| SSH Accessible | Works natively with Coolify |
| Scalable | Spin up more VMs as needed |
| Isolated | VMs separate from K8s workloads |
| Resource Managed | Kubernetes manages VM resources |
| GitOps Ready | Can be managed via ArgoCD/Flux |

## Upgrading

```bash
helm upgrade coolify ./charts/coolify \
  --namespace coolify \
  -f your-values.yaml
```

## Uninstalling

```bash
helm uninstall coolify --namespace coolify
```

**Note**: PVCs are not automatically deleted. To remove all data:

```bash
# Kubernetes/K3s
kubectl delete pvc -l app.kubernetes.io/instance=coolify -n coolify

# OKD/OpenShift
oc delete pvc -l app.kubernetes.io/instance=coolify -n coolify
```

## Troubleshooting

### Check pod status

```bash
kubectl get pods -n coolify
# or
oc get pods -n coolify
```

### Check pod logs

```bash
kubectl logs -l app.kubernetes.io/name=coolify -n coolify -f
```

### Check events

```bash
kubectl get events -n coolify --sort-by='.lastTimestamp'
```

### Database connectivity test

```bash
kubectl exec -it deploy/coolify -n coolify -- php artisan tinker
>>> DB::connection()->getPdo();
```

### OpenShift: Check SCC issues

```bash
oc get pod -n coolify -o yaml | grep -i scc
oc describe pod <pod-name> -n coolify | grep -A5 "Security Context"
```

## Support

- Documentation: https://coolify.io/docs
- Issues: https://github.com/coollabsio/coolify/issues
- Discord: https://discord.gg/coolify
