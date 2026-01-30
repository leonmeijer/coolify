# Coolify Platform Helm Chart

This is an umbrella Helm chart that deploys [Coolify](https://coolify.io) with [KubeVirt](https://kubevirt.io) Docker host virtual machines. It provides a complete self-hosted PaaS platform on Kubernetes with automatically provisioned Docker hosts for application deployment.

## Features

- **Coolify** - Self-hostable Heroku/Netlify/Vercel alternative
- **KubeVirt VMs** - Docker host virtual machines for running containers
- **Auto-provisioning** - VMs are automatically created and configured
- **Multi-platform** - Works on K3s, standard Kubernetes, OKD, and OpenShift
- **Health monitoring** - Automated health checks for all VMs
- **SSH key management** - Automatic SSH key generation and distribution
- **Dual deployment paths** - Deploy to Docker VMs or native Kubernetes

## Prerequisites

- Kubernetes 1.26+
- Helm 3.0+
- For non-OpenShift clusters: Hardware virtualization support (or use emulation mode)
- Storage class supporting dynamic provisioning
- For OpenShift: OpenShift Virtualization operator installed

## Quick Start

### K3s

```bash
helm install coolify-platform ./charts/coolify-platform \
  --namespace coolify \
  --create-namespace \
  -f ./charts/coolify-platform/values-k3s.yaml \
  --set coolify.coolify.config.appUrl=https://coolify.example.com \
  --set coolify.ingress.host=coolify.example.com
```

### OKD

```bash
helm install coolify-platform ./charts/coolify-platform \
  --namespace coolify \
  --create-namespace \
  -f ./charts/coolify-platform/values-okd.yaml \
  --set coolify.coolify.config.appUrl=https://coolify.apps.okd.example.com \
  --set coolify.route.host=coolify.apps.okd.example.com
```

### OpenShift

```bash
helm install coolify-platform ./charts/coolify-platform \
  --namespace coolify \
  --create-namespace \
  -f ./charts/coolify-platform/values-openshift.yaml \
  --set coolify.coolify.config.appUrl=https://coolify.apps.openshift.example.com \
  --set coolify.route.host=coolify.apps.openshift.example.com
```

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Kubernetes Cluster                                   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │                      coolify namespace                                   ││
│  │  ┌──────────────┐  ┌──────────┐  ┌───────────┐  ┌─────────┐            ││
│  │  │   Coolify    │  │  Soketi  │  │PostgreSQL │  │  Redis  │            ││
│  │  │   Web App    │  │WebSocket │  │           │  │         │            ││
│  │  └──────┬───────┘  └──────────┘  └───────────┘  └─────────┘            ││
│  └─────────│───────────────────────────────────────────────────────────────┘│
│            │                                                                 │
│            │ Two deployment paths:                                           │
│            │                                                                 │
│  ┌─────────┴─────────────────────────────────────────────────────┐          │
│  │                                                                │          │
│  ▼ Path A: Docker Host VMs                    ▼ Path B: Native K8s          │
│  ┌────────────────────────────────┐    ┌─────────────────────────┐          │
│  │     coolify-vms namespace      │    │   app-namespace         │          │
│  │  ┌──────────┐  ┌──────────┐   │    │  ┌─────────────────┐    │          │
│  │  │docker-   │  │docker-   │   │    │  │ Deployment      │    │          │
│  │  │host-00   │  │host-01   │   │    │  │ (your-app)      │    │          │
│  │  │ ┌──────┐ │  │ ┌──────┐ │   │    │  │  ┌───────────┐  │    │          │
│  │  │ │Docker│ │  │ │Docker│ │   │    │  │  │  Pod(s)   │  │    │          │
│  │  │ │ ├────┤ │  │ │ ├────┤ │   │    │  │  └───────────┘  │    │          │
│  │  │ │ │App │ │  │ │ │App │ │   │    │  └─────────────────┘    │          │
│  │  │ │ └────┘ │  │ │ └────┘ │   │    │  ┌─────────────────┐    │          │
│  │  │ └──────┘ │  │ └──────┘ │   │    │  │ Service         │    │          │
│  │  └──────────┘  └──────────┘   │    │  └─────────────────┘    │          │
│  └────────────────────────────────┘    │  ┌─────────────────┐    │          │
│                                        │  │ Ingress         │    │          │
│                                        │  └─────────────────┘    │          │
│                                        └─────────────────────────┘          │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Deployment Paths

Coolify Platform supports two deployment paths for your applications. The path is determined by the **Destination** you select when deploying.

### Path A: Docker Host VMs (Traditional Coolify)

Applications run as Docker containers inside KubeVirt virtual machines. This is the traditional Coolify deployment model.

```
┌─────────────────────────────────────────────────────────────────┐
│  Destination Selection                                          │
│                                                                  │
│   ● StandaloneDocker    → Path A (Docker on Server/VM)          │
│   ○ SwarmDocker         → Path A (Docker Swarm cluster)         │
│   ○ KubernetesDestination → Path B (Native Kubernetes)          │
└─────────────────────────────────────────────────────────────────┘
```

#### Deployment Flow (Path A)

```
1. User clicks "Deploy" in Coolify UI
           │
           ▼
2. Coolify connects via SSH to docker-host-00
   (using generated SSH keys stored in K8s Secret)
           │
           ▼
3. Coolify runs Docker commands:
   - docker pull <image>
   - docker run -d --name <app> ...
           │
           ▼
4. Coolify configures proxy inside VM
   (Traefik/Nginx running in the VM)
           │
           ▼
5. Application is running as Docker container inside the VM
```

#### Traffic Flow (Path A)

```
User Browser
     │
     ▼ HTTPS (app.example.com)
┌─────────────────────────────────┐
│  Kubernetes Ingress Controller  │  (or OpenShift Route)
│  (Traefik/Nginx)                │
└─────────────────────────────────┘
     │
     ▼ HTTP to VM Pod IP
┌─────────────────────────────────┐
│  virt-launcher Pod              │
│  (KubeVirt manages the VM)      │
│  ┌───────────────────────────┐  │
│  │  docker-host-00 VM        │  │
│  │  ┌─────────────────────┐  │  │
│  │  │ Traefik/Nginx Proxy │  │  │  ◄── Proxy inside VM
│  │  └──────────┬──────────┘  │  │
│  │             │             │  │
│  │  ┌──────────▼──────────┐  │  │
│  │  │  Your Application   │  │  │  ◄── Docker container
│  │  │  (Docker Container) │  │  │
│  │  └─────────────────────┘  │  │
│  └───────────────────────────┘  │
└─────────────────────────────────┘
```

#### Setup for Path A

1. **VMs are auto-provisioned** by this Helm chart
2. **Register VMs as Servers** in Coolify UI:
   ```
   Name: docker-host-00
   Host: docker-host-00-ssh.coolify-vms.svc.cluster.local
   Port: 22
   User: coolify
   SSH Key: [from kubectl get secret]
   ```
3. **Create StandaloneDocker destination** pointing to the server
4. **Deploy applications** selecting this destination

### Path B: Native Kubernetes

Applications run as native Kubernetes Deployments with Services and Ingress.

#### Deployment Flow (Path B)

```
1. User clicks "Deploy" in Coolify UI
   (Application configured with Kubernetes destination)
           │
           ▼
2. KubernetesDeploymentJob starts
           │
           ▼
3. KubernetesManifestGenerator creates:
   - Deployment (replicas, image, resources)
   - Service (ClusterIP)
   - Ingress (hostname routing)
   - ConfigMap/Secret (environment variables)
   - PVC (if persistent storage needed)
   - HPA (if autoscaling enabled)
           │
           ▼
4. KubernetesClientService applies manifests via K8s API
           │
           ▼
5. Application runs as native Kubernetes Pod(s)
```

#### Traffic Flow (Path B)

```
User Browser
     │
     ▼ HTTPS (app.example.com)
┌─────────────────────────────────┐
│  Kubernetes Ingress Controller  │
│  (Traefik/Nginx/OpenShift)      │
└─────────────────────────────────┘
     │
     ▼ HTTP (ClusterIP Service)
┌─────────────────────────────────┐
│  app-service                    │
│  (ClusterIP, port 80)           │
└─────────────────────────────────┘
     │
     ▼ Pod IP
┌─────────────────────────────────┐
│  app-xxxx-yyyy Pod              │
│  ┌───────────────────────────┐  │
│  │  Application Container    │  │
│  │  Port 80                  │  │
│  └───────────────────────────┘  │
└─────────────────────────────────┘
```

#### Setup for Path B

1. **Add Kubernetes Cluster** in Coolify UI with kubeconfig
2. **Create KubernetesDestination**:
   ```
   Cluster: your-cluster
   Namespace: coolify-apps
   Ingress Class: nginx (or traefik)
   Storage Class: local-path
   ```
3. **Deploy applications** selecting this destination

### Path Comparison

| Aspect | Path A (Docker Host VMs) | Path B (Native K8s) |
|--------|--------------------------|---------------------|
| **Where app runs** | Docker inside VM inside K8s | Native K8s Pod |
| **Scaling** | Manual or Coolify-managed | Kubernetes HPA |
| **Networking** | VM network → Pod network | Direct Pod network |
| **Proxy** | Traefik/Nginx in VM | K8s Ingress Controller |
| **Overhead** | Higher (VM + Docker layers) | Lower (just container) |
| **Compatibility** | Any Docker/Compose app | K8s-compatible apps |
| **SSH access** | Yes (for debugging) | kubectl exec |
| **Docker Compose** | Fully supported | Converted to manifests |

### When to Use Each Path

| Scenario | Recommended Path |
|----------|------------------|
| Docker Compose app with multiple services | **Path A** (Docker Host) |
| Need SSH access for debugging | **Path A** (Docker Host) |
| Stateful app with complex storage | **Path A** (Docker Host) |
| Legacy app expecting Docker environment | **Path A** (Docker Host) |
| Simple stateless web app | **Path B** (Native K8s) |
| Need horizontal autoscaling | **Path B** (Native K8s) |
| Want K8s features (HPA, PDB, etc.) | **Path B** (Native K8s) |
| Microservices architecture | **Path B** (Native K8s) |

### OpenShift Route Integration (Path A)

On OpenShift/OKD clusters, Path A applications are automatically exposed via **OpenShift Routes**
with the cluster's wildcard certificate. This provides automatic TLS termination using the
`*.apps.cluster.example.com` certificate.

#### How It Works

1. **Deployment**: App deploys as a Docker container inside the KubeVirt VM
2. **Route Sync**: After successful deployment, Coolify automatically creates:
   - **Service**: ClusterIP service targeting the VM's virt-launcher Pod
   - **Route**: OpenShift Route with edge TLS termination
3. **Traffic Flow**: User → Route (TLS) → Service → VM Pod → Docker Container

#### Traffic Flow with OpenShift Routes

```
User Browser
     │
     ▼ HTTPS (app.example.com)
┌─────────────────────────────────────┐
│  OpenShift Router                   │
│  (HAProxy with wildcard cert)       │
│  ┌───────────────────────────────┐  │
│  │ Route: app.apps.cluster.local │  │
│  │ TLS: edge termination         │  │
│  └───────────────────────────────┘  │
└─────────────────────────────────────┘
     │
     ▼ HTTP (internal)
┌─────────────────────────────────────┐
│  Service: app-svc                   │
│  (ClusterIP, selector: VM)          │
└─────────────────────────────────────┘
     │
     ▼ Pod IP:port
┌─────────────────────────────────────────────────────────┐
│  virt-launcher Pod (docker-host-00)                     │
│  ┌───────────────────────────────────────────────────┐  │
│  │  VM: docker-host-00                               │  │
│  │  ┌─────────────────────────────────────────────┐  │  │
│  │  │  Docker Container: your-app                 │  │  │
│  │  │  Port 80 (or configured port)               │  │  │
│  │  └─────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

#### Benefits

- **Automatic TLS**: Uses the cluster's wildcard certificate (e.g., `*.apps.okd.example.com`)
- **No Certificate Management**: No need to configure Let's Encrypt or other CA
- **Native Integration**: Routes appear in OpenShift Console under Networking > Routes
- **Edge Termination**: TLS terminates at the router, HTTP to backend (simplifies app config)

#### Configuration

The feature is automatic on OpenShift/OKD clusters. For custom domains outside the wildcard:

1. Configure the FQDN in Coolify (e.g., `https://myapp.example.com`)
2. Create DNS CNAME pointing to your OpenShift router
3. Optionally configure custom certificates in OpenShift

### Example: Deploying Uptime Kuma

#### Via Path A (Docker Host VM)

```
┌─────────────────────────────────────────────────────────────────┐
│  Deploy Uptime Kuma                                             │
│                                                                 │
│  Source: ● Docker Image                                         │
│  Image: louislam/uptime-kuma:1                                  │
│                                                                 │
│  Destination: [docker-host-00 (Standalone Docker) ▼]  ◄── Path A│
│                                                                 │
│  Domain: uptime-kuma.example.com                                │
│  Port: 3001                                                     │
│                                                                 │
│  [Deploy]                                                       │
└─────────────────────────────────────────────────────────────────┘
```

Result: Docker container running inside the VM, managed via SSH.

#### Via Path B (Native Kubernetes)

```
┌─────────────────────────────────────────────────────────────────┐
│  Deploy Uptime Kuma                                             │
│                                                                 │
│  Source: ● Docker Image                                         │
│  Image: louislam/uptime-kuma:1                                  │
│                                                                 │
│  Destination: [prod-cluster/apps (Kubernetes) ▼]  ◄──── Path B  │
│                                                                 │
│  Domain: uptime-kuma.example.com                                │
│  Port: 3001                                                     │
│                                                                 │
│  ┌─ Kubernetes Settings ─────────────────────────────────────┐  │
│  │  Replicas: 2                                              │  │
│  │  CPU Request: 100m    CPU Limit: 500m                     │  │
│  │  Memory Request: 256Mi    Memory Limit: 512Mi             │  │
│  │                                                           │  │
│  │  ☑ Enable Autoscaling                                     │  │
│  │    Min Replicas: 1    Max Replicas: 5                     │  │
│  │    Target CPU: 80%                                        │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                 │
│  [Deploy]                                                       │
└─────────────────────────────────────────────────────────────────┘
```

Result: Kubernetes Deployment + Service + Ingress + HPA.

## Configuration

### Global Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| `global.storageClass` | Storage class for all PVCs | `""` |
| `global.imagePullSecrets` | Image pull secrets | `[]` |

### Platform Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| `platform.type` | Platform type: auto, k3s, k8s, okd, openshift | `auto` |

### KubeVirt Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| `kubevirt.install` | Install KubeVirt (auto-disabled on OpenShift) | `true` |
| `kubevirt.version` | KubeVirt version | `v1.4.0` |
| `kubevirt.cdiVersion` | CDI version | `v1.60.3` |
| `kubevirt.useEmulation` | Use software emulation | `false` |
| `kubevirt.waitTimeout` | Wait timeout (seconds) | `600` |

### Docker Host VM Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| `dockerHosts.enabled` | Enable VM provisioning | `true` |
| `dockerHosts.count` | Number of VMs | `1` |
| `dockerHosts.namespace` | Namespace for VMs | `coolify-vms` |
| `dockerHosts.resources.cores` | CPU cores per VM | `4` |
| `dockerHosts.resources.memory` | Memory per VM | `8Gi` |
| `dockerHosts.resources.diskSize` | Disk size per VM | `100Gi` |
| `dockerHosts.image.source` | Container disk source | `quay.io/containerdisks/ubuntu:22.04` |
| `dockerHosts.ssh.username` | SSH username | `coolify` |
| `dockerHosts.ssh.keyType` | SSH key type | `ed25519` |

### Coolify Settings

The `coolify` section passes values to the Coolify subchart. See `charts/coolify/values.yaml` for all available options.

| Parameter | Description | Default |
|-----------|-------------|---------|
| `coolify.enabled` | Enable Coolify deployment | `true` |
| `coolify.coolify.config.appUrl` | Coolify URL (required) | `https://coolify.example.com` |

## Post-Installation

After installation:

1. Access the Coolify UI at your configured URL
2. Create an admin account
3. Add the Docker host VMs as servers (they should be auto-registered)
4. Start deploying applications!

### Retrieving SSH Keys

```bash
# Get the SSH private key
kubectl get secret coolify-platform-ssh-keys -n coolify \
  -o jsonpath='{.data.private_key}' | base64 -d > coolify-key

chmod 600 coolify-key

# SSH to a VM (from within the cluster)
ssh -i coolify-key coolify@docker-host-00-ssh.coolify-vms.svc.cluster.local
```

### Checking VM Status

```bash
# List VMs
kubectl get vm -n coolify-vms

# Check VM instances (running state)
kubectl get vmi -n coolify-vms

# View VM console (requires virtctl)
virtctl console docker-host-00 -n coolify-vms
```

## Upgrading

```bash
helm upgrade coolify-platform ./charts/coolify-platform \
  -n coolify \
  -f your-values.yaml
```

## Uninstalling

```bash
# Uninstall the release (this will delete all VMs!)
helm uninstall coolify-platform -n coolify

# Optionally delete the VM namespace
kubectl delete namespace coolify-vms

# If KubeVirt was installed, optionally remove it
kubectl delete kubevirt kubevirt -n kubevirt
kubectl delete -f https://github.com/kubevirt/kubevirt/releases/download/v1.4.0/kubevirt-operator.yaml
```

## Troubleshooting

### VMs not starting

1. Check if KubeVirt is ready:
   ```bash
   kubectl get kubevirt kubevirt -n kubevirt
   ```

2. Check virt-launcher pods:
   ```bash
   kubectl get pods -n coolify-vms
   kubectl describe pod <virt-launcher-pod> -n coolify-vms
   ```

3. If running on VMs without nested virtualization, enable emulation:
   ```bash
   helm upgrade coolify-platform ... --set kubevirt.useEmulation=true
   ```

### SSH not working

1. Wait for cloud-init to complete (can take 2-5 minutes)
2. Check VM console:
   ```bash
   virtctl console docker-host-00 -n coolify-vms
   ```

3. Verify cloud-init finished:
   ```bash
   # Inside the VM
   cat /var/lib/cloud/instance/boot-finished
   ```

### Coolify can't connect to VMs

1. Verify network policies allow traffic
2. Check the service exists:
   ```bash
   kubectl get svc -n coolify-vms
   ```

3. Test connectivity from Coolify pod:
   ```bash
   kubectl exec -it <coolify-pod> -n coolify -- nc -zv docker-host-00-ssh.coolify-vms.svc.cluster.local 22
   ```

## License

Apache-2.0

## Links

- [Coolify Documentation](https://coolify.io/docs)
- [KubeVirt Documentation](https://kubevirt.io/user-guide)
- [GitHub Repository](https://github.com/coollabsio/coolify)
