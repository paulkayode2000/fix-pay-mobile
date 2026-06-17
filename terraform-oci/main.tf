terraform {
  required_providers {
    oci = {
      source  = "oracle/oci"
      version = "~> 5.0"
    }
  }
}

provider "oci" {
  tenancy_ocid     = var.tenancy_ocid
  user_ocid        = var.user_ocid
  fingerprint      = var.fingerprint
  private_key_path = var.private_key_path
  region           = var.region
}

# --- Network Resources ---

resource "oci_core_vcn" "fixpay_vcn" {
  compartment_id = var.compartment_ocid
  cidr_block     = "10.0.0.0/16"
  display_name   = "fixpay-vcn"
  dns_label      = "fixpayvcn"
}

resource "oci_core_internet_gateway" "fixpay_ig" {
  compartment_id = var.compartment_ocid
  vcn_id         = oci_core_vcn.fixpay_vcn.id
  display_name   = "fixpay-ig"
  enabled        = true
}

resource "oci_core_route_table" "fixpay_rt" {
  compartment_id = var.compartment_ocid
  vcn_id         = oci_core_vcn.fixpay_vcn.id
  display_name   = "fixpay-rt"

  route_rules {
    destination       = "0.0.0.0/0"
    destination_type  = "CIDR_BLOCK"
    network_entity_id = oci_core_internet_gateway.fixpay_ig.id
  }
}

resource "oci_core_security_list" "fixpay_sl" {
  compartment_id = var.compartment_ocid
  vcn_id         = oci_core_vcn.fixpay_vcn.id
  display_name   = "fixpay-sl"

  egress_security_rules {
    destination = "0.0.0.0/0"
    protocol    = "all"
  }

  ingress_security_rules {
    protocol = "6" # TCP
    source   = "0.0.0.0/0"
    tcp_options {
      max = 22
      min = 22
    }
  }

  ingress_security_rules {
    protocol = "6" # TCP
    source   = "0.0.0.0/0"
    tcp_options {
      max = 80
      min = 80
    }
  }

  ingress_security_rules {
    protocol = "6" # TCP
    source   = "0.0.0.0/0"
    tcp_options {
      max = 443
      min = 443
    }
  }

  ingress_security_rules {
    protocol = "6" # TCP
    source   = "0.0.0.0/0"
    tcp_options {
      max = 8000
      min = 8000
    }
  }
}

resource "oci_core_subnet" "fixpay_subnet" {
  compartment_id    = var.compartment_ocid
  vcn_id            = oci_core_vcn.fixpay_vcn.id
  cidr_block        = "10.0.1.0/24"
  display_name      = "fixpay-subnet"
  route_table_id    = oci_core_route_table.fixpay_rt.id
  security_list_ids = [oci_core_security_list.fixpay_sl.id]
}

# --- Compute Resource ---

# Get Availability Domains
data "oci_identity_availability_domains" "ads" {
  compartment_id = var.compartment_ocid
}

# Get latest Ubuntu 22.04 AMD Image
data "oci_core_images" "ubuntu_amd" {
  compartment_id           = var.compartment_ocid
  operating_system         = "Canonical Ubuntu"
  operating_system_version = "22.04"
  shape                    = "VM.Standard.E2.1.Micro"
  sort_by                  = "TIMECREATED"
  sort_order               = "DESC"
}

resource "oci_core_instance" "fixpay_server" {
  availability_domain = data.oci_identity_availability_domains.ads.availability_domains[0].name
  compartment_id      = var.compartment_ocid
  display_name        = "fixpay-server"
  shape               = "VM.Standard.E2.1.Micro"

  create_vnic_details {
    subnet_id                 = oci_core_subnet.fixpay_subnet.id
    display_name              = "primary-vnic"
    assign_public_ip          = true
    assign_private_dns_record = true
  }

  source_details {
    source_type             = "image"
    source_id               = data.oci_core_images.ubuntu_amd.images[0].id
    boot_volume_size_in_gbs = 50
  }

  metadata = {
    ssh_authorized_keys = var.ssh_public_key
    user_data           = base64encode(<<-EOF
      #!/bin/bash
      # Update apt
      apt-get update -y
      apt-get upgrade -y

      # Install Docker
      apt-get install -y apt-transport-https ca-certificates curl software-properties-common
      curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg
      echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
      apt-get update -y
      apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

      # Allow ubuntu user to run docker
      usermod -aG docker ubuntu

      # Create app directory
      mkdir -p /var/www/fixpay
      chown ubuntu:ubuntu /var/www/fixpay

      # Enable docker service
      systemctl enable docker
      systemctl start docker
    EOF
    )
  }
}
