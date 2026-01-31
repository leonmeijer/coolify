# Implementation Tasks: KubeVirt Integratie

## Overzicht

Dit document beschrijft de implementatietaken voor de KubeVirt integratie met Coolify. De taken zijn georganiseerd in fasen en geprioriteerd volgens de requirements.

---

## Fase 1: Foundation (P0)

### Task 1.1: Umbrella Chart Structuur
**Requirement:** 11
**Geschatte tijd:** 4 uur
**Afhankelijkheden:** Geen

**Beschrijving:**
Maak de basis Helm chart structuur aan voor coolify-platform.

**Subtaken:**
- [ ] Maak `coolify-platform/` directory structuur
- [ ] Maak `Chart.yaml` met dependencies
- [ ] Maak basis `values.yaml` met alle configuratie opties
- [ ] Kopieer bestaande `coolify` chart naar `charts/coolify/`
- [ ] Maak `_helpers.tpl` met template functies
- [ ] Maak `.helmignore`
- [ ] Voer `helm lint` uit

**Acceptatiecriteria:**
- `helm lint charts/coolify-platform` slaagt
- `helm dependency update` werkt
- `helm template` genereert valide YAML

---

### Task 1.2: Platform Detectie
**Requirement:** 1
**Geschatte tijd:** 3 uur
**Afhankelijkheden:** Task 1.1

**Beschrijving:**
Implementeer platform detectie logica voor OpenShift vs Kubernetes.

**Subtaken:**
- [ ] Maak platform detectie helper in `_helpers.tpl`
- [ ] Voeg conditionele logica toe voor OpenShift-specifieke resources
- [ ] Maak platform-specifieke values files:
  - [ ] `values-k3s.yaml`
  - [ ] `values-okd.yaml`
  - [ ] `values-openshift.yaml`
- [ ] Test detectie op beide platforms

**Acceptatiecriteria:**
- Chart detecteert OpenShift correct via API check
- Chart selecteert juiste resources per platform

---

### Task 1.3: KubeVirt Installer Job
**Requirement:** 2
**Geschatte tijd:** 6 uur
**Afhankelijkheden:** Task 1.2

**Beschrijving:**
Maak pre-install hook voor automatische KubeVirt installatie.

**Subtaken:**
- [ ] Maak `templates/pre-install/rbac.yaml` voor installer permissions
- [ ] Maak `templates/pre-install/kubevirt-installer.yaml` Job
- [ ] Implementeer KubeVirt operator installatie
- [ ] Implementeer CDI installatie
- [ ] Implementeer wait-for-ready logica
- [ ] Voeg skip logica toe voor OpenShift
- [ ] Voeg KVM check toe
- [ ] Test op K3s cluster

**Acceptatiecriteria:**
- KubeVirt wordt automatisch geïnstalleerd op K3s
- Installatie wordt overgeslagen op OpenShift
- Job faalt graceful bij geen KVM support

---

### Task 1.4: SSH Key Generation
**Requirement:** 4
**Geschatte tijd:** 3 uur
**Afhankelijkheden:** Task 1.1

**Beschrijving:**
Implementeer automatische SSH key generatie.

**Subtaken:**
- [ ] Maak `templates/pre-install/ssh-keygen.yaml` Job
- [ ] Maak Secret template voor SSH keys
- [ ] Voeg optie toe voor bestaande keys
- [ ] Voeg `helm.sh/resource-policy: keep` annotation toe
- [ ] Test key generatie en hergebruik

**Acceptatiecriteria:**
- SSH keypair wordt gegenereerd bij eerste install
- Keys blijven behouden bij upgrade
- Bestaande keys kunnen worden gebruikt

---

### Task 1.5: VirtualMachine Template
**Requirement:** 3
**Geschatte tijd:** 6 uur
**Afhankelijkheden:** Task 1.3, Task 1.4

**Beschrijving:**
Maak VirtualMachine CR template voor Docker host VMs.

**Subtaken:**
- [ ] Maak `templates/docker-hosts/virtualmachine.yaml`
- [ ] Maak `templates/docker-hosts/datavolume.yaml`
- [ ] Maak `templates/docker-hosts/cloudinit-secret.yaml`
- [ ] Implementeer iteratie voor meerdere VMs
- [ ] Configureer resource limits
- [ ] Test VM creation op KubeVirt cluster

**Acceptatiecriteria:**
- VMs worden correct aangemaakt
- Cloud-init configureert Docker en SSH
- Resources zijn configureerbaar

---

### Task 1.6: VM Network Services
**Requirement:** 5
**Geschatte tijd:** 3 uur
**Afhankelijkheden:** Task 1.5

**Beschrijving:**
Maak Kubernetes Services voor VM SSH toegang.

**Subtaken:**
- [ ] Maak `templates/docker-hosts/service.yaml`
- [ ] Implementeer ClusterIP, NodePort, LoadBalancer opties
- [ ] Voeg DNS documentatie toe
- [ ] Test SSH connectivity via Service

**Acceptatiecriteria:**
- SSH bereikbaar via Service DNS naam
- Service type configureerbaar

---

### Task 1.7: Coolify Registration Job
**Requirement:** 7
**Geschatte tijd:** 6 uur
**Afhankelijkheden:** Task 1.5, Task 1.6

**Beschrijving:**
Maak post-install job voor automatische server registratie in Coolify.

**Subtaken:**
- [ ] Maak `templates/post-install/registration-job.yaml`
- [ ] Implementeer Coolify health wait
- [ ] Implementeer VM ready wait (SSH + Docker)
- [ ] Implementeer Coolify API call voor registratie
- [ ] Voeg retry logica toe
- [ ] Maak registratie optional (voor handmatige setup)
- [ ] Test volledige flow

**Acceptatiecriteria:**
- VMs worden automatisch geregistreerd in Coolify
- Registratie is idempotent
- Graceful handling bij ontbrekende API token

---

## Fase 2: Platform Support (P1)

### Task 2.1: VM Lifecycle Management
**Requirement:** 6
**Geschatte tijd:** 4 uur
**Afhankelijkheden:** Fase 1 compleet

**Beschrijving:**
Implementeer VM start/stop/scale operaties.

**Subtaken:**
- [ ] Implementeer scale-up via helm upgrade
- [ ] Voeg scale-down protection toe
- [ ] Maak cleanup job voor VM deletion
- [ ] Implementeer VM restart via annotation
- [ ] Documenteer lifecycle operaties

**Acceptatiecriteria:**
- VMs kunnen worden geschaald via helm upgrade
- Scale-down vereist expliciete bevestiging
- VMs kunnen worden gestopt/gestart

---

### Task 2.2: Health Monitoring
**Requirement:** 8
**Geschatte tijd:** 4 uur
**Afhankelijkheden:** Task 1.5

**Beschrijving:**
Implementeer VM health monitoring.

**Subtaken:**
- [ ] Maak `templates/post-install/health-cronjob.yaml`
- [ ] Implementeer SSH health check
- [ ] Implementeer Docker health check
- [ ] Voeg labels toe voor health status
- [ ] Maak Kubernetes Events voor status changes
- [ ] Configureer check interval

**Acceptatiecriteria:**
- Health status zichtbaar via kubectl
- Events worden aangemaakt bij status changes
- Interval configureerbaar

---

### Task 2.3: Storage Configuratie
**Requirement:** 9
**Geschatte tijd:** 3 uur
**Afhankelijkheden:** Task 1.5

**Beschrijving:**
Uitbreiden van storage configuratie opties.

**Subtaken:**
- [ ] Voeg storage class configuratie toe
- [ ] Implementeer containerDisk vs dataVolume keuze
- [ ] Voeg extra data disk optie toe voor /var/lib/docker
- [ ] Test met verschillende storage classes

**Acceptatiecriteria:**
- Storage class configureerbaar
- Extra disks kunnen worden toegevoegd
- Werkt met lokale en cloud storage

---

### Task 2.4: OpenShift Specifieke Config
**Requirement:** 10
**Geschatte tijd:** 4 uur
**Afhankelijkheden:** Fase 1 compleet

**Beschrijving:**
Implementeer OpenShift-specifieke resources.

**Subtaken:**
- [ ] Maak Route template (alternatief voor Ingress)
- [ ] Maak SCC template (optioneel)
- [ ] Maak NetworkPolicy voor cross-namespace communicatie
- [ ] Update documentatie met OpenShift setup
- [ ] Test op OKD cluster

**Acceptatiecriteria:**
- Chart werkt op OpenShift zonder handmatige changes
- NetworkPolicy correct geconfigureerd
- Documentatie compleet

---

### Task 2.5: Values Schema
**Requirement:** 12
**Geschatte tijd:** 3 uur
**Afhankelijkheden:** Fase 1 compleet

**Beschrijving:**
Maak JSON Schema voor values validatie.

**Subtaken:**
- [ ] Maak `values.schema.json`
- [ ] Definieer types en constraints voor alle values
- [ ] Definieer required fields
- [ ] Test validatie met ongeldige values
- [ ] Voeg schema reference toe aan Chart.yaml

**Acceptatiecriteria:**
- Ongeldige configuratie wordt afgewezen
- Error messages zijn duidelijk
- Alle values zijn gedocumenteerd

---

### Task 2.6: Security Hardening
**Requirement:** 16
**Geschatte tijd:** 4 uur
**Afhankelijkheden:** Fase 1 compleet

**Beschrijving:**
Implementeer security best practices.

**Subtaken:**
- [ ] Update cloud-init met security hardening
- [ ] Configureer SSH hardening (no password, no root)
- [ ] Voeg firewall configuratie toe
- [ ] Review RBAC permissions (minimal)
- [ ] Voeg securityContext toe aan alle pods
- [ ] Documenteer security configuratie

**Acceptatiecriteria:**
- VMs hebben hardened SSH config
- RBAC is minimal
- Security best practices gedocumenteerd

---

## Fase 3: Polish (P2)

### Task 3.1: Observability
**Requirement:** 13
**Geschatte tijd:** 4 uur
**Afhankelijkheden:** Fase 2 compleet

**Beschrijving:**
Implementeer monitoring en metrics.

**Subtaken:**
- [ ] Maak ServiceMonitor template (optioneel)
- [ ] Definieer custom metrics
- [ ] Maak Grafana dashboard ConfigMap
- [ ] Voeg log aggregation documentatie toe

**Acceptatiecriteria:**
- Metrics beschikbaar in Prometheus (indien enabled)
- Dashboard beschikbaar voor Grafana

---

### Task 3.2: Backup Procedures
**Requirement:** 14
**Geschatte tijd:** 3 uur
**Afhankelijkheden:** Fase 2 compleet

**Beschrijving:**
Documenteer backup en recovery procedures.

**Subtaken:**
- [ ] Voeg Velero annotations toe aan DataVolumes
- [ ] Documenteer VolumeSnapshot procedure
- [ ] Documenteer disaster recovery stappen
- [ ] Test restore procedure

**Acceptatiecriteria:**
- Backup procedure gedocumenteerd
- Restore procedure getest en gedocumenteerd

---

### Task 3.3: Upgrade Procedures
**Requirement:** 15
**Geschatte tijd:** 3 uur
**Afhankelijkheden:** Fase 2 compleet

**Beschrijving:**
Implementeer en documenteer upgrade procedures.

**Subtaken:**
- [ ] Maak helm test hook
- [ ] Documenteer upgrade procedure
- [ ] Documenteer rollback procedure
- [ ] Test upgrade van v0.1.0 naar v0.2.0

**Acceptatiecriteria:**
- Helm test valideert installatie
- Upgrade procedure gedocumenteerd
- Rollback procedure gedocumenteerd

---

### Task 3.4: CI/CD Pipeline
**Requirement:** 18
**Geschatte tijd:** 4 uur
**Afhankelijkheden:** Fase 2 compleet

**Beschrijving:**
Implementeer CI pipeline voor chart testing.

**Subtaken:**
- [ ] Maak GitHub Actions workflow
- [ ] Implementeer helm lint
- [ ] Implementeer helm template validation
- [ ] Implementeer kubeconform validation
- [ ] Voeg security scanning toe

**Acceptatiecriteria:**
- CI pipeline runt bij elke PR
- Alle checks slagen voor merge

---

### Task 3.5: Documentatie
**Requirement:** 17
**Geschatte tijd:** 4 uur
**Afhankelijkheden:** Alle voorgaande taken

**Beschrijving:**
Finaliseer alle documentatie.

**Subtaken:**
- [ ] Update README.md met complete guide
- [ ] Maak CHANGELOG.md
- [ ] Voeg voorbeelden toe voor elk platform
- [ ] Maak troubleshooting guide
- [ ] Review en finaliseer alle inline comments
- [ ] Maak FAQ sectie

**Acceptatiecriteria:**
- Documentatie volledig en accuraat
- Troubleshooting guide behandelt common issues
- Voorbeelden voor elk platform

---

## Tijdlijn

| Fase | Taken | Geschatte Tijd | Milestone |
|------|-------|----------------|-----------|
| Fase 1 | 1.1 - 1.7 | 31 uur | MVP: Werkende installatie |
| Fase 2 | 2.1 - 2.6 | 22 uur | Production Ready |
| Fase 3 | 3.1 - 3.5 | 18 uur | Complete Release |
| **Totaal** | | **71 uur** | |

---

## Definition of Done

Een taak is compleet wanneer:
1. Code is geïmplementeerd en getest
2. `helm lint` slaagt
3. `helm template` genereert valide YAML
4. Handmatige test op target platform succesvol
5. Documentatie is bijgewerkt
6. PR is reviewed en gemerged

---

## Test Matrix

| Platform | Fase 1 | Fase 2 | Fase 3 |
|----------|--------|--------|--------|
| K3s (local) | ✓ | ✓ | ✓ |
| K3d (CI) | ✓ | ✓ | ✓ |
| OKD 4.14 | ✓ | ✓ | - |
| OpenShift 4.14 | - | ✓ | - |
| Kind (CI) | ✓ | - | - |
