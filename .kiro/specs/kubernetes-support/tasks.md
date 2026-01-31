# Implementatieplan: Kubernetes Support

## Overzicht

Dit implementatieplan beschrijft de stappen voor het toevoegen van native Kubernetes/OKD/OpenShift ondersteuning aan Coolify. De implementatie volgt het bestaande destination patroon en is opgedeeld in logische fasen: database modellen, services, jobs, en UI componenten.

## Tasks

- [x] 1. Database Migraties en Modellen
  - [x] 1.1 Maak database migratie voor kubernetes_clusters tabel
    - Maak `database/migrations/xxxx_create_kubernetes_clusters_table.php`
    - Velden: id, uuid, name, description, team_id, cluster_type, api_server_url, kubeconfig (encrypted), context_name, default_namespace, is_reachable, last_checked_at, timestamps, soft deletes
    - Foreign key naar teams tabel
    - _Requirements: 1.1, 9.1_

  - [x] 1.2 Maak database migratie voor kubernetes_destinations tabel
    - Maak `database/migrations/xxxx_create_kubernetes_destinations_table.php`
    - Velden: id, uuid, name, kubernetes_cluster_id, namespace, default_cpu_limit, default_memory_limit, default_cpu_request, default_memory_request, default_replicas, ingress_class, storage_class, timestamps
    - Foreign key naar kubernetes_clusters tabel
    - Unique constraint op (kubernetes_cluster_id, namespace)
    - _Requirements: 2.1, 2.4_

  - [x] 1.3 Maak database migratie voor kubernetes_deployment_settings tabel
    - Maak `database/migrations/xxxx_create_kubernetes_deployment_settings_table.php`
    - Velden: id, application_id, replicas, cpu_limit, memory_limit, cpu_request, memory_request, autoscaling_enabled, min_replicas, max_replicas, target_cpu_utilization, target_memory_utilization, timestamps
    - Foreign key naar applications tabel
    - _Requirements: 5.1, 5.2, 5.3_

  - [x] 1.4 Maak KubernetesCluster model
    - Maak `app/Models/KubernetesCluster.php`
    - Implementeer fillable, casts (encrypted kubeconfig), relaties (team, destinations)
    - Implementeer testConnection(), getNamespaces(), getClient() methodes
    - Implementeer soft deletes en UUID generatie
    - _Requirements: 1.1, 1.2, 1.4, 1.5, 10.1_

  - [x] 1.5 Schrijf property test voor KubernetesCluster model opslag
    - **Property 1: Cluster Configuratie Opslag**
    - **Validates: Requirements 1.1**

  - [x] 1.6 Maak KubernetesDestination model
    - Maak `app/Models/KubernetesDestination.php`
    - Implementeer fillable, relaties (cluster, applications, services, databases via morphMany)
    - Implementeer server() accessor voor compatibiliteit
    - Volg het patroon van StandaloneDocker model
    - _Requirements: 2.2, 2.4_

  - [x] 1.7 Schrijf property test voor KubernetesDestination polymorphic relaties
    - **Property 3: Destination Polymorphic Relaties**
    - **Validates: Requirements 2.2**

  - [x] 1.8 Maak KubernetesDeploymentSettings model
    - Maak `app/Models/KubernetesDeploymentSettings.php`
    - Implementeer fillable, casts, relatie naar Application
    - _Requirements: 5.2, 5.3_

- [x] 2. Checkpoint - Database laag compleet
  - Voer migraties uit en verifieer dat alle modellen correct werken
  - Ensure all tests pass, ask the user if questions arise.

- [x] 3. Kubernetes Client Service
  - [x] 3.1 Installeer Kubernetes PHP client library
    - Voeg `maclof/kubernetes-client` of vergelijkbare library toe aan composer.json
    - Configureer autoloading
    - _Requirements: 1.2_

  - [x] 3.2 Maak KubernetesClientService
    - Maak `app/Services/KubernetesClientService.php`
    - Implementeer constructor met KubernetesCluster parameter
    - Implementeer namespace operaties: createNamespace(), namespaceExists(), getNamespaces()
    - Implementeer deployment operaties: applyManifest(), deleteResource(), getDeployment(), getDeploymentStatus(), scaleDeployment()
    - Implementeer pod operaties: getPods(), getPodLogs(), streamPodLogs()
    - Implementeer service/ingress operaties: getService(), getIngress()
    - Implementeer health checks: checkApiHealth(), checkRbacPermissions()
    - _Requirements: 1.2, 1.4, 4.2, 9.4_

  - [x] 3.3 Schrijf property test voor credential encryption
    - **Property 12: Credential Encryption**
    - **Validates: Requirements 9.1, 9.2, 9.3**

- [x] 4. Manifest Generator Service
  - [x] 4.1 Maak KubernetesManifestGenerator service
    - Maak `app/Services/KubernetesManifestGenerator.php`
    - Implementeer constructor met Application/Service en KubernetesDestination parameters
    - Implementeer generateDeployment() methode
    - Implementeer generateService() methode
    - Implementeer generateIngress() methode
    - Implementeer generateConfigMap() en generateSecret() methodes
    - Implementeer generatePersistentVolumeClaim() methode
    - Implementeer generateHorizontalPodAutoscaler() methode
    - Implementeer generateAll() methode die alle relevante manifests genereert
    - Implementeer toYaml() en toArray() export methodes
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

  - [x] 4.2 Schrijf property test voor manifest generatie correctheid
    - **Property 6: Manifest Generatie Correctheid**
    - **Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6**

  - [x] 4.3 Schrijf property test voor YAML round-trip
    - **Property 7: Manifest YAML Round-Trip**
    - **Validates: Requirements 3.7**

  - [x] 4.4 Implementeer health check probe conversie
    - Voeg buildLivenessProbe() en buildReadinessProbe() methodes toe
    - Converteer Coolify health check configuratie naar Kubernetes probe specs
    - _Requirements: 4.5_

  - [x] 4.5 Schrijf property test voor health check probe conversie
    - **Property 8: Health Check Probe Conversie**
    - **Validates: Requirements 4.5**

  - [x] 4.6 Implementeer HPA manifest generatie
    - Voeg generateHorizontalPodAutoscaler() implementatie toe
    - Ondersteun min/max replicas en CPU/memory targets
    - _Requirements: 5.2, 5.3_

  - [x] 4.7 Schrijf property test voor HPA manifest generatie
    - **Property 9: HPA Manifest Generatie**
    - **Validates: Requirements 5.2, 5.3**

  - [x] 4.8 Implementeer Docker Compose conversie
    - Voeg fromDockerCompose() methode toe
    - Converteer services naar Deployments
    - Converteer volumes naar PVCs
    - Converteer networks naar Services
    - Converteer environment variables naar ConfigMaps/Secrets
    - Log waarschuwingen voor niet-ondersteunde features
    - Implementeer Kompose label support (service.type, service.expose, hpa.*, volume.size, etc.)
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

  - [x] 4.9 Schrijf property test voor Docker Compose conversie
    - **Property 10: Docker Compose Conversie**
    - **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5**

- [x] 5. Checkpoint - Services compleet
  - Verifieer dat KubernetesClientService en ManifestGenerator correct werken
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Kubernetes Deployment Job
  - [x] 6.1 Maak KubernetesDeploymentJob
    - Maak `app/Jobs/KubernetesDeploymentJob.php`
    - Implementeer constructor met application_deployment_queue_id
    - Implementeer handle() methode met deployment flow
    - Implementeer buildAndPushImage() voor git-based deployments
    - Implementeer generateManifests() die ManifestGenerator aanroept
    - Implementeer applyManifests() die KubernetesClientService aanroept
    - Implementeer waitForDeployment() voor status monitoring
    - Implementeer performHealthCheck() voor health verificatie
    - Implementeer rollback() voor fout herstel
    - Implementeer streamLogs() voor log streaming
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 8.1, 8.2, 8.3_

  - [x] 6.2 Schrijf property test voor image reference correctheid
    - **Property 11: Image Reference Correctheid**
    - **Validates: Requirements 8.2, 8.3, 8.4**

  - [x] 6.3 Update ApplicationDeploymentQueue voor Kubernetes tracking
    - Voeg kubernetes_cluster_id en kubernetes_namespace kolommen toe via migratie
    - Update model met nieuwe velden
    - _Requirements: 10.3_

  - [x] 6.4 Schrijf property test voor deployment history tracking
    - **Property 14: Deployment History Tracking**
    - **Validates: Requirements 10.3**

- [x] 7. Delete Validatie Logica
  - [x] 7.1 Implementeer cluster delete validatie
    - Voeg canDelete() methode toe aan KubernetesCluster model
    - Check voor actieve destinations met deployments
    - Blokkeer delete indien actieve resources bestaan
    - _Requirements: 1.6_

  - [x] 7.2 Schrijf property test voor cluster delete validatie
    - **Property 2: Cluster Delete Validatie**
    - **Validates: Requirements 1.6**

  - [x] 7.3 Implementeer destination delete validatie
    - Voeg canDelete() methode toe aan KubernetesDestination model
    - Check voor gekoppelde applications, services, databases
    - Blokkeer delete indien resources gekoppeld zijn
    - _Requirements: 2.5_

  - [x] 7.4 Schrijf property test voor destination delete validatie
    - **Property 5: Destination Delete Validatie**
    - **Validates: Requirements 2.5**

- [x] 8. Checkpoint - Backend compleet
  - Verifieer dat alle backend componenten correct samenwerken
  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Livewire UI Componenten
  - [x] 9.1 Maak Kubernetes cluster index pagina
    - Maak `app/Livewire/Kubernetes/Index.php` en bijbehorende view
    - Toon lijst van clusters voor het huidige team
    - Toon cluster status (reachable/unreachable)
    - Link naar cluster detail pagina
    - _Requirements: 6.3, 10.2_

  - [x] 9.2 Maak Kubernetes cluster create/edit component
    - Maak `app/Livewire/Kubernetes/Form.php` en bijbehorende view
    - Formulier voor naam, beschrijving, cluster type
    - Kubeconfig upload/paste functionaliteit
    - Verbinding test knop
    - Namespace selectie na succesvolle verbinding
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_

  - [x] 9.3 Maak Kubernetes destination component
    - Maak `app/Livewire/Kubernetes/Destination.php` en bijbehorende view
    - Namespace selectie of aanmaak
    - Resource defaults configuratie (CPU, memory limits)
    - Ingress class en storage class selectie
    - _Requirements: 2.1, 2.3, 2.4_

  - [x] 9.4 Update resource creation flow voor Kubernetes keuze
    - Update `app/Livewire/Project/New/Select.php` componenten
    - Voeg deployment target keuze toe: Docker vs Kubernetes
    - Filter destinations op basis van keuze
    - _Requirements: 6.1, 6.2_

  - [x] 9.5 Maak Kubernetes deployment settings component
    - Maak `app/Livewire/Project/Application/KubernetesSettings.php` en view
    - Replicas configuratie
    - Resource limits configuratie
    - Autoscaling toggle en configuratie
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 6.5_

  - [x] 9.6 Maak Kubernetes pod status en logs component
    - Maak `app/Livewire/Project/Application/KubernetesPods.php` en view
    - Toon pod lijst met status
    - Pod log viewer met real-time streaming
    - Events weergave
    - _Requirements: 6.4, 6.6_

- [x] 10. Routes en Navigatie
  - [x] 10.1 Voeg Kubernetes routes toe
    - Update `routes/web.php` met Kubernetes cluster routes
    - /kubernetes - cluster index
    - /kubernetes/create - nieuwe cluster
    - /kubernetes/{uuid} - cluster detail
    - /kubernetes/{uuid}/destinations - destinations beheer
    - _Requirements: 6.3_

  - [x] 10.2 Update navigatie menu
    - Voeg Kubernetes sectie toe aan server/team navigatie
    - Link naar cluster beheer pagina
    - _Requirements: 6.3_

- [x] 11. Multi-Cluster Ondersteuning
  - [x] 11.1 Implementeer multi-cluster queries
    - Voeg ownedByCurrentTeam() scope toe aan KubernetesCluster
    - Implementeer cluster selectie in deployment flow
    - _Requirements: 10.1, 10.2_

  - [x] 11.2 Schrijf property test voor multi-cluster per team
    - **Property 13: Multi-Cluster Per Team**
    - **Validates: Requirements 10.1**

  - [x] 11.3 Implementeer destination resource defaults toepassing
    - Update ManifestGenerator om destination defaults te gebruiken
    - Fallback naar destination defaults wanneer applicatie geen expliciete limits heeft
    - _Requirements: 2.4_

  - [x] 11.4 Schrijf property test voor destination resource defaults
    - **Property 4: Destination Resource Defaults**
    - **Validates: Requirements 2.4**

- [x] 12. Finale Checkpoint
  - Voer volledige test suite uit
  - Verifieer alle UI flows werken correct
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Alle taken zijn verplicht, inclusief property tests voor uitgebreide testing vanaf het begin
- Elke task refereert naar specifieke requirements voor traceerbaarheid
- Checkpoints zorgen voor incrementele validatie
- Property tests valideren universele correctheidseigenschappen
- Unit tests valideren specifieke voorbeelden en edge cases
- De implementatie volgt het bestaande Coolify patroon voor destinations (StandaloneDocker, SwarmDocker)
